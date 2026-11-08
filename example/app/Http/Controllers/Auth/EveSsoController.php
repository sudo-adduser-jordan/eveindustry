<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;

class EveSsoController extends Controller
{
    private const REQUIRED_SCOPES = [
        ['scope' => 'publicData', 'label' => 'Public Character Data', 'description' => 'Access your character\'s name, portrait, and public information.'],
        ['scope' => 'esi-skills.read_skills.v1', 'label' => 'Trained Skills', 'description' => 'Read your character\'s trained skills.'],
        ['scope' => 'esi-skills.read_skillqueue.v1', 'label' => 'Skill Queue', 'description' => 'Read your character\'s skill training queue.'],
        ['scope' => 'esi-location.read_location.v1', 'label' => 'Current Location', 'description' => 'Read your character\'s current solar system and station.'],
        ['scope' => 'esi-industry.read_character_jobs.v1', 'label' => 'Character Industry Jobs', 'description' => 'Read your character\'s active and completed industry jobs.'],
        ['scope' => 'esi-characters.read_blueprints.v1', 'label' => 'Character Blueprints', 'description' => 'Read blueprints owned by your character.'],
        ['scope' => 'esi-assets.read_assets.v1', 'label' => 'Character Assets', 'description' => 'Read assets owned by your character.'],
        ['scope' => 'esi-universe.read_structures.v1', 'label' => 'Structure Names', 'description' => 'Resolve names of player-owned structures where your assets are located.'],
        ['scope' => 'esi-planets.manage_planets.v1', 'label' => 'Planetary Interaction', 'description' => 'Track your planetary colonies, extraction cycles, and production chains.'],
        ['scope' => 'esi-search.search_structures.v1', 'label' => 'Structure Search', 'description' => 'Search for player-owned structures by name.']
    ];

    private const OPTIONAL_SCOPES = [
        ['scope' => 'esi-industry.read_corporation_jobs.v1', 'label' => 'Corporation Industry Jobs', 'description' => 'View your corporation\'s industry jobs alongside your personal jobs.'],
        ['scope' => 'esi-characters.read_corporation_roles.v1', 'label' => 'Corporation Roles', 'description' => 'Determine your corporation roles to access corporation-level features.'],
        ['scope' => 'esi-corporations.read_blueprints.v1', 'label' => 'Corporation Blueprints', 'description' => 'View blueprints owned by your corporation.'],
        ['scope' => 'esi-assets.read_corporation_assets.v1', 'label' => 'Corporation Assets', 'description' => 'View assets owned by your corporation.'],
        ['scope' => 'esi-wallet.read_corporation_wallets.v1', 'label' => 'Corporation Wallet', 'description' => 'View your corporation\'s wallet balance and journal.'],
        ['scope' => 'esi-corporations.read_projects.v1', 'label' => 'Corporation Projects', 'description' => 'View your corporation\'s projects and track their progress and rewards.'],
    ];

    public function __construct(protected EsiService $esi) {}

    /** A demo session must never link a real character to the shared account. */
    private function leaveDemoSession(Request $request): void
    {
        if (Auth::user()?->isDemo()) {
            Auth::logout();
            $request->session()->regenerate();
        }
    }

    public function showScopes(Request $request): View
    {
        $this->leaveDemoSession($request);

        return view('auth.sso-scopes', [
            'requiredScopes' => self::REQUIRED_SCOPES,
            'optionalScopes' => self::OPTIONAL_SCOPES,
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        $this->leaveDemoSession($request);

        $allowedOptional = array_column(self::OPTIONAL_SCOPES, 'scope');

        $optionalScopes = $request->boolean('scopes_configured')
            ? array_values(array_intersect((array) $request->input('optional_scopes', []), $allowedOptional))
            : $allowedOptional;

        $scopes = array_merge(array_column(self::REQUIRED_SCOPES, 'scope'), $optionalScopes);

        return Socialite::driver('eveonline')
            ->scopes($scopes)
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $eveUser = Socialite::driver('eveonline')->user();

            // Extract character id and name from attributes or the JWT user payload
            $attributes = $eveUser->attributes ?? [];
            $payload = $eveUser->user ?? [];

            $characterId = null;
            if (! empty($attributes['character_id'])) {
                $characterId = (int) $attributes['character_id'];
            } elseif (! empty($payload['sub'])) {
                // format: "CHARACTER:EVE:977687735"
                $parts = explode(':', (string) $payload['sub']);
                $characterId = isset($parts[2]) ? (int) $parts[2] : null;
            }

            $characterName = $attributes['character_name'] ?? $payload['name'] ?? $eveUser->getName() ?? 'Unknown';

            if (empty($characterId)) {
                Log::error('EVE SSO: missing character id in provider response', ['user' => $payload, 'attributes' => $attributes]);

                return redirect()->route('login')->with('error', 'Authentication failed: missing character id.');
            }

            $accessToken = $eveUser->token ?? null;
            $refreshToken = $eveUser->refreshToken ?? null;
            $expiresIn = $eveUser->expiresIn ?? 1199;

            $scopes = [];
            if (isset($eveUser->accessTokenResponseBody['scope'])) {
                $scopes = explode(' ', $eveUser->accessTokenResponseBody['scope']);
            } elseif (! empty($payload['scp'])) {
                // EVE SSO v2 embeds scopes in the JWT access token's "scp" claim
                $scopes = is_array($payload['scp']) ? $payload['scp'] : [$payload['scp']];
            }

            // Optionally validate token iat/nbf/exp with EsiService if available
            try {
                if (method_exists($this->esi, 'validateJwt') && $accessToken) {
                    $this->esi->validateJwt($accessToken);
                }
            } catch (\Exception $e) {
                Log::warning('EVE SSO: token validation warning', ['msg' => $e->getMessage()]);
                // proceed — ESI API requests may still work; decide to block if needed
            }

            // Get character info from ESI (best-effort)
            $charInfo = null;
            try {
                $charInfo = $this->esi->getCharacter($characterId);
            } catch (\Exception $e) {
                Log::warning("ESI error fetching character {$characterId}: ".$e->getMessage());
            }

            $corpInfo = $charInfo ? $this->esi->getCorporation($charInfo['corporation_id']) : null;
            $corporationId = $charInfo['corporation_id'] ?? 0;
            $corporationName = $corpInfo['name'] ?? ($charInfo['corporation_name'] ?? 'Unknown');
            $allianceId = $charInfo['alliance_id'] ?? null;
            $allianceName = null;

            if ($allianceId) {
                try {
                    $allianceInfo = $this->esi->getAlliance($allianceId);
                    $allianceName = $allianceInfo['name'] ?? null;
                } catch (\Exception $e) {
                    Log::warning("ESI error fetching alliance {$allianceId}: ".$e->getMessage());
                }
            }

            // Find or create character and user
            $character = Character::find($characterId);

            if ($character) {
                $character->update([
                    'name' => $characterName,
                    'corporation_id' => $corporationId,
                    'corporation_name' => $corporationName,
                    'alliance_id' => $allianceId,
                    'alliance_name' => $allianceName,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_expires_at' => now()->addSeconds($expiresIn),
                    'scopes' => $scopes,
                    'is_esi_active' => true,
                    'affiliation_updated_at' => ($corporationName !== 'Unknown') ? now() : null,
                ]);

                $user = $character->user;
            } else {
                if (Auth::check()) {
                    $user = Auth::user();
                    $isMain = ! $user->characters()->exists();
                } else {
                    $user = User::create([
                        'name' => $characterName,
                        'email' => "{$characterId}@eve.local",
                        'is_active' => true,
                    ]);
                    $isMain = true;
                    session()->flash('analytics_event', 'sign_up');
                    session()->flash('analytics_event_params', ['method' => 'eve_sso']);
                }

                Character::create([
                    'character_id' => $characterId,
                    'user_id' => $user->id,
                    'name' => $characterName,
                    'corporation_id' => $corporationId,
                    'corporation_name' => $corporationName,
                    'alliance_id' => $allianceId,
                    'alliance_name' => $allianceName,
                    'is_main' => $isMain,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_expires_at' => now()->addSeconds($expiresIn),
                    'scopes' => $scopes,
                    'affiliation_updated_at' => ($corporationName !== 'Unknown') ? now() : null,
                ]);
            }

            $user->update(['last_login_at' => now()]);
            Auth::login($user, true);

            Log::info("EVE SSO login: {$characterName} ({$characterId})");

            return redirect()->route('dashboard')
                ->with('success', "Welcome, {$characterName}!");

        } catch (\Exception $e) {
            Log::error('EVE SSO error: '.$e->getMessage(), ['exception' => $e]);

            return redirect()->route('login')
                ->with('error', 'Authentication failed. Please try again.');
        }
    }
}
