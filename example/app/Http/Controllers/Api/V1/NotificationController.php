<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Character;
use App\Models\PushSubscription;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Web push settings for the signed-in user: which devices are subscribed and
 * which notification types are switched on.
 */
class NotificationController extends Controller
{
    public function __construct(
        private NotificationPreferenceService $preferences,
        private NotificationDispatcher $dispatcher,
    ) {}

    /**
     * Notification settings.
     *
     * Whether push is configured on this server, the public VAPID key the browser
     * subscribes with, how many devices are subscribed, every notification type
     * with the user's effective setting (registry default unless they changed it),
     * and quiet hours. `available` is false for corporation types when none of the
     * user's characters is in a corporation.
     *
     * @response array{data: array{enabled: bool, vapid_public_key: ?string, devices: int, types: list<array{key: string, label: string, description: string, category: string, available: bool, enabled: bool, default_enabled: bool, lead_minutes: ?int, lead_options: ?list<int>, supports_characters: bool, character_ids: list<int>}>, quiet_hours: array{start: ?string, end: ?string, timezone: string}}, meta: object}
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->config($request));
    }

    /**
     * Register this device.
     *
     * Stores the browser's push subscription (`PushSubscription.toJSON()`); the same
     * endpoint is updated rather than duplicated.
     *
     * @response array{data: array{devices: int}, meta: object}
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);

        $user = $request->user();
        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        $existing = PushSubscription::where('endpoint_hash', $hash)->first();

        // An endpoint belongs to one browser profile; a different user signing in on it takes it over.
        PushSubscription::updateOrCreate(['endpoint_hash' => $hash], [
            'user_id' => $user->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'subscribed_at' => $existing && $existing->user_id === $user->id ? $existing->subscribed_at : now(),
        ]);

        return ApiResponse::success(['devices' => $user->pushSubscriptions()->count()]);
    }

    /**
     * Remove this device.
     *
     * @response array{data: array{devices: int}, meta: object}
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        $request->user()->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return ApiResponse::success(['devices' => $request->user()->pushSubscriptions()->count()]);
    }

    /**
     * Change settings.
     *
     * A partial change: only the listed types and fields are updated. `lead_minutes`
     * must be one of the type's `lead_options`; `character_ids` (empty means all)
     * must be the user's own characters and only applies to types that support it.
     * Send `quiet_hours` with null start/end to clear it.
     *
     * @response array{data: array{enabled: bool, vapid_public_key: ?string, devices: int, types: list<array{key: string, label: string, description: string, category: string, available: bool, enabled: bool, default_enabled: bool, lead_minutes: ?int, lead_options: ?list<int>, supports_characters: bool, character_ids: list<int>}>, quiet_hours: array{start: ?string, end: ?string, timezone: string}}, meta: object}
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'types' => ['sometimes', 'array'],
            'types.*.enabled' => ['sometimes', 'boolean'],
            'types.*.lead_minutes' => ['sometimes', 'integer'],
            'types.*.character_ids' => ['sometimes', 'array', 'max:50'],
            'types.*.character_ids.*' => ['integer'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.start' => ['nullable', 'date_format:H:i'],
            'quiet_hours.end' => ['nullable', 'date_format:H:i'],
            'quiet_hours.timezone' => ['sometimes', 'timezone:all'],
        ]);

        $user = $request->user();
        $errors = [];
        $ownCharacters = Character::where('user_id', $user->id)->pluck('character_id')->map(fn ($id) => (int) $id)->all();

        foreach ($data['types'] ?? [] as $key => $change) {
            $type = NotificationType::tryFrom((string) $key);
            if ($type === null) {
                $errors["types.$key"] = ['Unknown notification type.'];

                continue;
            }
            if (isset($change['lead_minutes']) && ! in_array($change['lead_minutes'], $type->leadOptions() ?? [], true)) {
                $errors["types.$key.lead_minutes"] = ['This notification has no such lead time.'];
            }
            if (array_diff($change['character_ids'] ?? [], $ownCharacters) !== []) {
                $errors["types.$key.character_ids"] = ['Unknown character.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->preferences->update($user, $data['types'] ?? []);

        if (isset($data['quiet_hours'])) {
            $current = $this->preferences->quietHours($user);
            $quiet = array_merge($current, $data['quiet_hours']);
            // A half-set window is meaningless; treat it as cleared.
            if ($quiet['start'] === null || $quiet['end'] === null) {
                $quiet['start'] = $quiet['end'] = null;
            }
            $this->preferences->updateQuietHours($user, $quiet);
        }

        return ApiResponse::success($this->config($request));
    }

    /**
     * Send a test notification.
     *
     * Delivers a sample message to every subscribed device. `sent` is how many
     * devices accepted it; subscriptions the push service reports as gone are removed.
     *
     * @response array{data: array{sent: int}, meta: object}
     */
    public function test(Request $request): JsonResponse
    {
        if (! WebPushSender::configured()) {
            abort(503, 'Push notifications are not configured on this server.');
        }

        return ApiResponse::success(['sent' => $this->dispatcher->sendTest($request->user())]);
    }

    private function config(Request $request): array
    {
        $user = $request->user();
        $effective = $this->preferences->effective($user);

        return [
            'enabled' => WebPushSender::configured(),
            'vapid_public_key' => config('webpush.vapid.public_key') ?: null,
            'devices' => $user->pushSubscriptions()->count(),
            'types' => array_map(fn (NotificationType $type) => [
                'key' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'category' => $type->category(),
                'available' => $this->preferences->isAvailable($user, $type),
                'enabled' => $effective[$type->value]['enabled'],
                'default_enabled' => $type->defaultEnabled(),
                'lead_minutes' => $effective[$type->value]['lead_minutes'],
                'lead_options' => $type->leadOptions(),
                'supports_characters' => $type->supportsCharacters(),
                'character_ids' => $effective[$type->value]['character_ids'],
            ], NotificationType::cases()),
            'quiet_hours' => $this->preferences->quietHours($user),
        ];
    }
}
