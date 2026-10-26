<?php

namespace App\Http\Middleware;

use App\Models\Signer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate peran untuk seluruh API /api/v1: hanya pengguna yang memiliki
 * (minimal satu) baris signer AKTIF yang boleh melanjutkan. Pengguna
 * sekadar login (token valid) tapi tanpa peran penandatangan ditolak 403
 * dengan shape error konsisten {error:{code:'FORBIDDEN',message}} —
 * dipetakan oleh exception handler di bootstrap/app.php.
 */
class DocumentRoleGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || Signer::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->doesntExist()) {
            abort(403, 'Pengguna tidak memiliki peran signer yang aktif.');
        }

        return $next($request);
    }
}
