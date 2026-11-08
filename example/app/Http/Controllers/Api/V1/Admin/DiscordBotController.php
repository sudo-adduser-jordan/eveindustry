<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DiscordBotUpdateRequest;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Admin\DiscordBotSettingsResource;
use App\Http\Responses\ApiResponse;
use App\Models\DiscordBotSetting;
use Illuminate\Http\JsonResponse;

class DiscordBotController extends Controller
{
    /**
     * Discord welcome bot settings. The token is never returned, only whether one is stored.
     *
     * @response array{data: DiscordBotSettingsResource, meta: object}
     */
    public function show(NoInputRequest $request): JsonResponse
    {
        return ApiResponse::success(new DiscordBotSettingsResource(DiscordBotSetting::getSettings()));
    }

    /**
     * Save the bot settings.
     *
     * @response array{data: DiscordBotSettingsResource, meta: object}
     */
    public function update(DiscordBotUpdateRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['bot_token', 'clear_bot_token']);
        $data['is_enabled'] = $request->boolean('is_enabled');

        if ($request->filled('bot_token')) {
            $data['bot_token'] = $request->validated('bot_token');
        } elseif ($request->boolean('clear_bot_token')) {
            $data['bot_token'] = null;
        }

        $settings = DiscordBotSetting::getSettings();
        $settings->update($data);

        return ApiResponse::success(new DiscordBotSettingsResource($settings));
    }
}
