<?php
declare(strict_types=1);

namespace Vault24\Controllers;

use Vault24\Auth\Principal;
use Vault24\Http\HttpException;
use Vault24\Http\Request;
use Vault24\Http\Response;
use Vault24\Http\Validator;
use Vault24\Policies\TransferPolicy;
use Vault24\Support\Config;
use Vault24\Support\Database;
use Vault24\Transfers\TransferService;

final class TransferController
{
    public function __construct(private TransferService $transfers, private TransferPolicy $policy, private Database $db, private Config $cfg) {}

    public function create(Request $r, Principal $p): Response
    {
        $key = $r->header('idempotency-key');
        if ($key === null || !preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $key)) {
            throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', ['idempotency_key' => 'Idempotency-Key header (8-64 chars: letters, digits, _ -) is required.']);
        }
        $v = (new Validator($r->json()))->onlyKeys(['destination_account_ref', 'amount_minor', 'currency', 'note']);
        $dest = $v->pattern('destination_account_ref', '/^\d{10}$/', 'Invalid account reference.');
        $amount = $v->integer('amount_minor', 1, (int) $this->cfg->get('max_transfer_minor'));
        if ($v->has('currency')) {
            $v->pattern('currency', '/^' . preg_quote((string) $this->cfg->get('currency'), '/') . '$/', 'Unsupported currency.');
        }
        $note = $v->has('note') ? $v->string('note', 0, 60) : '';
        $v->fail();

        $res = $this->transfers->create($p, $dest, $amount, $key, $note);
        // The caller's own balance as committed by the server (the UI never computes balances).
        $bal = (int) $this->db->one('SELECT balance_minor AS b FROM accounts WHERE user_id = ?', [$p->userId])['b'];
        return Response::data($res['transfer'] + ['replayed' => $res['replayed'], 'source_balance_minor' => $bal], $res['replayed'] ? 200 : 201);
    }

    public function show(Request $r, Principal $p, array $params): Response
    {
        $ref = $params['reference'] ?? '';
        if (!preg_match('/^TRF-[A-F0-9]{16}$/', $ref) || !$this->policy->canView($p, $ref)) {
            throw new HttpException(404, 'not_found', 'Resource not found.'); // do not reveal that it exists
        }
        $t = $this->db->one(
            'SELECT t.reference, t.status, t.amount_minor, t.currency, t.note, t.created_at, sa.account_ref AS source_account_ref, da.account_ref AS destination_account_ref
               FROM transfers t JOIN accounts sa ON sa.id = t.source_account_id JOIN accounts da ON da.id = t.destination_account_id WHERE t.reference = ?',
            [$ref]
        );
        $t['amount_minor'] = (int) $t['amount_minor'];
        return Response::data($t);
    }
}
