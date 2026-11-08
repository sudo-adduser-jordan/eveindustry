<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Demo\DemoDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoLoginController extends Controller
{
    public function __invoke(Request $request, DemoDataService $demo): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $user = User::where('is_demo', true)->first() ?? $demo->reset();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/app/dashboard');
    }
}
