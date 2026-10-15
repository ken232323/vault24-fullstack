<?php
declare(strict_types=1);

namespace Vault24\Controllers;

use Vault24\Auth\Principal;
use Vault24\Http\HttpException;
use Vault24\Http\Request;
use Vault24\Http\Response;
use Vault24\Http\Validator;
use Vault24\Support\Database;
use Vault24\Support\Money;

final class AccountController
{
    public function __construct(private Database $db) {}

    public function show(Request $r, Principal $p): Response
    {
        $a = $this->db->one('SELECT account_ref, currency, balance_minor, status FROM accounts WHERE user_id = ?', [$p->userId]);
        if ($a === null) {
            throw new HttpException(404, 'not_found', 'Resource not found.');
        }
        return Response::data([
            'account_ref' => $a['account_ref'],
            'currency' => $a['currency'],
            'balance_minor' => (int) $a['balance_minor'],
            'balance' => Money::format((int) $a['balance_minor']),
            'status' => $a['status'],
            'simulated' => true,
        ]);
    }

    public function transactions(Request $r, Principal $p): Response
    {
        [$page, $perPage] = Validator::pagination($r->query);
        $acc = $this->db->one('SELECT id FROM accounts WHERE user_id = ?', [$p->userId]);
        if ($acc === null) {
            throw new HttpException(404, 'not_found', 'Resource not found.');
        }
        // Every query is scoped to the caller's own account id (derived from the session).
        $total = (int) $this->db->one('SELECT COUNT(*) AS n FROM ledger_entries WHERE account_id = ?', [$acc['id']])['n'];
        $rows = $this->db->all(
            "SELECT t.reference, t.status, t.note, le.direction, le.amount_minor, t.currency, t.created_at,
                    ca.account_ref AS counterparty_account_ref, cu.name AS counterparty_name
               FROM ledger_entries le
               JOIN transfers t ON t.id = le.transfer_id
               JOIN accounts ca ON ca.id = CASE WHEN le.direction = 'debit' THEN t.destination_account_id ELSE t.source_account_id END
               JOIN users cu ON cu.id = ca.user_id
              WHERE le.account_id = ?
              ORDER BY le.id DESC LIMIT ? OFFSET ?",
            [$acc['id'], $perPage, ($page - 1) * $perPage]
        );
        foreach ($rows as &$row) {
            $row['amount_minor'] = (int) $row['amount_minor'];
        }
        return Response::data(['items' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
    }
}
