<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckAffiliate
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->user()->hasRole('afiliado')) {
            return $next($request);
        }

        Auth::logout();
        return redirect()->to(url('/affiliate/login'))->with(['error' => 'Você não tem autorização']);
    }
}
