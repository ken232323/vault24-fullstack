<?php
declare(strict_types=1);

namespace Vault24\Policies;

use Vault24\Auth\Principal;
use Vault24\Support\Database;

/** Object-level authorization (middleware alone is never enough). */
final class TransferPolicy
{
    public function __construct(private Database $db) {}

    /** Owner of either leg, or staff/admin. Callers answer 404 (not 403) when this returns false. */
    public function canView(Principal $p, string $reference): bool
    {
        if ($p->isStaffOrAdmin()) {
            return true;
        }
        $row = $this->db->one(
            'SELECT 1 FROM transfers t
               JOIN accounts a ON a.id IN (t.source_account_id, t.destination_account_id)
              WHERE t.reference = ? AND a.user_id = ?',
            [$reference, $p->userId]
        );
        return $row !== null;
    }
}
