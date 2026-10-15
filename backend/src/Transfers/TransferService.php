<?php
declare(strict_types=1);

namespace Vault24\Transfers;

use Vault24\Audit\AuditService;
use Vault24\Auth\Principal;
use Vault24\Http\HttpException;
use Vault24\Support\Config;
use Vault24\Support\Database;

/**
 * The ONLY code that writes account balances. Debit, credit, transfer row, ledger entries and the
 * idempotency record commit together or roll back together.
 */
final class TransferService
{
    /** Test seam: called right after the debit so tests can prove a mid-transfer failure rolls back. */
    public ?\Closure $faultAfterDebit = null;

    public function __construct(private Database $db, private Config $cfg, private AuditService $audit) {}

    /** @return array{transfer:array,replayed:bool} */
    public function create(Principal $user, string $destinationRef, int $amountMinor, string $idempotencyKey, string $note = ''): array
    {
        $currency = (string) $this->cfg->get('currency');
        $requestHash = hash('sha256', json_encode([$destinationRef, $amountMinor, $currency, $note], JSON_THROW_ON_ERROR));

        try {
            $result = $this->db->transaction(function () use ($user, $destinationRef, $amountMinor, $idempotencyKey, $currency, $requestHash, $note) {
                // BEGIN IMMEDIATE holds the write lock: every read below is stable until COMMIT.

                // 1. Deduplicate: same key + same payload => original result; different payload => 409.
                $prior = $this->db->one(
                    'SELECT k.request_hash, k.transfer_id FROM idempotency_keys k WHERE k.user_id = ? AND k.key = ?',
                    [$user->userId, $idempotencyKey]
                );
                if ($prior !== null) {
                    if (!hash_equals($prior['request_hash'], $requestHash)) {
                        throw new HttpException(409, 'idempotency_key_conflict', 'This idempotency key was already used with a different request.');
                    }
                    return ['transfer' => $this->view((int) $prior['transfer_id']), 'replayed' => true];
                }

                // 2. Authorize + validate: source is derived from the authenticated caller, never from the request.
                $src = $this->db->one('SELECT * FROM accounts WHERE user_id = ?', [$user->userId]);
                if ($src === null || $src['status'] !== 'active') {
                    throw new HttpException(403, 'account_unavailable', 'Your account cannot send transfers.');
                }
                $dst = $this->db->one('SELECT * FROM accounts WHERE account_ref = ?', [$destinationRef]);
                if ($dst === null || $dst['status'] !== 'active' || (int) $dst['id'] === (int) $src['id']) {
                    throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', ['destination_account_ref' => 'Destination account is not valid.']);
                }

                // 3. Re-check funds on the locked, freshly read row (never trust a client balance).
                if ((int) $src['balance_minor'] < $amountMinor) {
                    throw new HttpException(422, 'insufficient_funds', 'Insufficient funds.');
                }
                if ((int) $dst['balance_minor'] + $amountMinor > (int) $this->cfg->get('max_balance_minor')) {
                    throw new HttpException(422, 'transfer_not_allowed', 'This transfer cannot be completed.');
                }

                // 4. Apply entries (guarded UPDATE is defence in depth on top of the lock + CHECK constraint).
                $debited = $this->db->run('UPDATE accounts SET balance_minor = balance_minor - ? WHERE id = ? AND balance_minor >= ?', [$amountMinor, $src['id'], $amountMinor])->rowCount();
                if ($debited !== 1) {
                    throw new HttpException(422, 'insufficient_funds', 'Insufficient funds.');
                }
                if ($this->faultAfterDebit !== null) {
                    ($this->faultAfterDebit)();
                }
                $this->db->run('UPDATE accounts SET balance_minor = balance_minor + ? WHERE id = ?', [$amountMinor, $dst['id']]);

                $reference = 'TRF-' . strtoupper(bin2hex(random_bytes(8)));
                $transferId = $this->db->insert(
                    'INSERT INTO transfers (reference, source_account_id, destination_account_id, amount_minor, currency, status, note) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$reference, $src['id'], $dst['id'], $amountMinor, $currency, 'completed', $note]
                );
                $this->db->run('INSERT INTO ledger_entries (transfer_id, account_id, direction, amount_minor) VALUES (?, ?, ?, ?)', [$transferId, $src['id'], 'debit', $amountMinor]);
                $this->db->run('INSERT INTO ledger_entries (transfer_id, account_id, direction, amount_minor) VALUES (?, ?, ?, ?)', [$transferId, $dst['id'], 'credit', $amountMinor]);
                $this->db->run('INSERT INTO idempotency_keys (key, user_id, request_hash, transfer_id) VALUES (?, ?, ?, ?)', [$idempotencyKey, $user->userId, $requestHash, $transferId]);

                // 5. Invariant: exactly one debit and one credit of the same amount.
                $sums = $this->db->one(
                    "SELECT SUM(CASE WHEN direction='debit' THEN amount_minor END) AS d, SUM(CASE WHEN direction='credit' THEN amount_minor END) AS c, COUNT(*) AS n
                       FROM ledger_entries WHERE transfer_id = ?",
                    [$transferId]
                );
                if ((int) $sums['n'] !== 2 || (int) $sums['d'] !== $amountMinor || (int) $sums['c'] !== $amountMinor) {
                    throw new \LogicException('Ledger invariant violated');
                }
                return ['transfer' => $this->view($transferId), 'replayed' => false, 'src' => (int) $src['id'], 'dst' => (int) $dst['id']];
            });
        } catch (HttpException $e) {
            $this->audit->record('transfer.failed', $user->userId, 'failure', 'transfer', null, ['reason' => $e->errorCode, 'amount_minor' => $amountMinor, 'currency' => $currency]);
            throw $e;
        } catch (\Throwable $e) {
            $this->audit->record('transfer.failed', $user->userId, 'failure', 'transfer', null, ['reason' => 'internal_error', 'amount_minor' => $amountMinor, 'currency' => $currency]);
            throw $e; // Kernel turns this into a generic 500
        }

        if (!$result['replayed']) {
            $this->audit->record('transfer.created', $user->userId, 'success', 'transfer', $result['transfer']['reference'], [
                'reference' => $result['transfer']['reference'],
                'source_account_id' => $result['src'],
                'destination_account_id' => $result['dst'],
                'amount_minor' => $amountMinor,
                'currency' => $currency,
            ]);
        }
        return ['transfer' => $result['transfer'], 'replayed' => $result['replayed']];
    }

    /** Caller-facing representation of a transfer (no internal ids). */
    public function view(int $transferId): array
    {
        $t = $this->db->one(
            'SELECT t.reference, t.status, t.amount_minor, t.currency, t.note, t.created_at, sa.account_ref AS source_account_ref, da.account_ref AS destination_account_ref
               FROM transfers t JOIN accounts sa ON sa.id = t.source_account_id JOIN accounts da ON da.id = t.destination_account_id
              WHERE t.id = ?',
            [$transferId]
        );
        return $t ?? [];
    }
}
