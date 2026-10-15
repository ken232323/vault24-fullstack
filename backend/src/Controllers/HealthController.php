<?php
declare(strict_types=1);

namespace Vault24\Controllers;

use Vault24\Http\Response;

final class HealthController
{
    public function show(): Response
    {
        return Response::data(['status' => 'ok']); // minimal: no versions, env vars or topology
    }
}
