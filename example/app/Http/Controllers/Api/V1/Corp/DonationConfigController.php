<?php

namespace App\Http\Controllers\Api\V1\Corp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Corp\EnableCorporationFeatureRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Character;
use App\Services\CorporateDonationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class DonationConfigController extends Controller
{
    public function __construct(private CorporateDonationService $donations) {}

    /**
     * Donation tracking status.
     *
     * Whether donation tracking is enabled for the corporation of one of the
     * user's characters, who provides the wallet access, and which of the
     * user's characters could enable it (corporate wallet scope). Open to any
     * signed-in user, so the page can offer to enable tracking where
     * `GET /corporation/donations` answers 403.
     *
     * @response array{data: array{enabled: bool, enabled_by_me: bool, corporation_name: string|null, provider_character_name: string|null, can_enable: bool, wallet_characters: list<array{character_id: int, name: string, corporation_name: string|null}>}, meta: object}
     */
    public function status(Request $request): JsonResponse
    {
        return ApiResponse::success($this->statusFor((int) $request->user()->id));
    }

    /**
     * Enable donation tracking.
     *
     * Uses the given character's corporate wallet access (or the first
     * eligible one). 422 when the user has no character with the
     * `esi-wallet.read_corporation_wallets.v1` scope or the given one is not
     * eligible. Returns the new status.
     *
     * @response array{data: array{enabled: bool, enabled_by_me: bool, corporation_name: string|null, provider_character_name: string|null, can_enable: bool, wallet_characters: list<array{character_id: int, name: string, corporation_name: string|null}>}, meta: object}
     */
    public function enable(EnableCorporationFeatureRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $characters = $this->donations->getUserWalletCharacters($userId);
        $characterId = $request->characterId();
        $character = $characterId ? $characters->firstWhere('character_id', $characterId) : $characters->first();

        if (! $character) {
            throw ValidationException::withMessages(['character_id' => $characters->isEmpty()
                ? 'No character with corporate wallet access found. Ensure your character has the esi-wallet.read_corporation_wallets.v1 scope.'
                : 'The selected character does not have corporate wallet access.']);
        }

        $this->donations->enable($character, $userId);

        return ApiResponse::success($this->statusFor($userId));
    }

    /**
     * Disable donation tracking.
     *
     * Only the user who enabled it may; anyone else gets 403. Returns the new
     * status.
     *
     * @response array{data: array{enabled: bool, enabled_by_me: bool, corporation_name: string|null, provider_character_name: string|null, can_enable: bool, wallet_characters: list<array{character_id: int, name: string, corporation_name: string|null}>}, meta: object}
     */
    public function disable(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $config = $this->donations->getConfigForUser($userId);

        if (! $config || (int) $config->enabled_by_user_id !== $userId) {
            throw new AuthorizationException('Only the user who enabled this feature can disable it.');
        }

        $this->donations->disable($config->corporation_id);

        return ApiResponse::success($this->statusFor($userId));
    }

    /**
     * Refresh donation data.
     *
     * Forgets the cached wallet journals of the corporation, so the next read
     * asks ESI again. Returns 204.
     */
    public function refresh(Request $request): Response
    {
        $config = $this->donations->getConfigForUser((int) $request->user()->id);

        if ($config) {
            $this->donations->clearCorporationCache($config->corporation_id);
        }

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function statusFor(int $userId): array
    {
        $config = $this->donations->getConfigForUser($userId);
        $walletCharacters = $this->donations->getUserWalletCharacters($userId);

        return [
            'enabled' => $config !== null,
            'enabled_by_me' => $config !== null && (int) $config->enabled_by_user_id === $userId,
            'corporation_name' => $config?->corporation_name,
            'provider_character_name' => $config?->character?->name,
            'can_enable' => $walletCharacters->isNotEmpty(),
            'wallet_characters' => $walletCharacters->map(fn (Character $c) => [
                'character_id' => (int) $c->character_id,
                'name' => (string) $c->name,
                'corporation_name' => $c->corporation_name,
            ])->values()->all(),
        ];
    }
}
