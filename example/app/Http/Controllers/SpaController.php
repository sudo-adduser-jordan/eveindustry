<?php

namespace App\Http\Controllers;

use App\Services\ThemeService;
use Illuminate\Http\Response;

/**
 * Serves the React app (built into public/app by `npm run build` in
 * frontend/). Every /app/* path that is not a built asset lands here and the
 * client-side router takes over. Sign-in is the existing session login.
 * The active theme (APP_THEME) is injected into the shell on the way out.
 */
class SpaController extends Controller
{
    public function __invoke(ThemeService $themes): Response
    {
        $index = public_path('app/index.html');

        if (! is_file($index)) {
            return response('The web app has not been built. Run "npm run build" in frontend/.', 503);
        }

        // The shell is tiny and references hashed assets, so it must never be cached.
        return response($themes->apply((string) file_get_contents($index)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
