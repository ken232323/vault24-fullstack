<?php
declare(strict_types=1);

namespace Vault24\Controllers;

use Vault24\Audit\AuditService;
use Vault24\Auth\Principal;
use Vault24\Http\HttpException;
use Vault24\Http\Request;
use Vault24\Http\Response;
use Vault24\Http\Validator;
use Vault24\Support\Database;

final class ProfileController
{
    public function __construct(private Database $db, private AuditService $audit) {}

    /** Only `name` is editable. role/status/email/account fields are rejected (mass-assignment protection). */
    public function update(Request $r, Principal $p): Response
    {
        $body = $r->json();
        if ($body === []) {
            throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', ['name' => 'Nothing to update.']);
        }
        $v = (new Validator($body))->onlyKeys(['name']);
        $name = $v->string('name', 1, 100);
        $v->fail();
        $this->db->run('UPDATE users SET name = ? WHERE id = ?', [$name, $p->userId]); // id from the principal only
        $this->audit->record('profile.updated', $p->userId, 'success', 'user', $p->publicId, ['changed_fields' => ['name']]);
        return Response::data(['id' => $p->publicId, 'name' => $name, 'email' => $p->email, 'role' => $p->role]);
    }
}
