<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->status === 'active', 401, 'Phiên không hợp lệ.');

        return $next($request);
    }
}
