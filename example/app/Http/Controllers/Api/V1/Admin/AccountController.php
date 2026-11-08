<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AccountUpdateRequest;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    /**
     * The login email of the signed-in admin.
     *
     * @response array{data: array{email: string}, meta: object}
     */
    public function show(NoInputRequest $request): JsonResponse
    {
        return ApiResponse::success(['email' => (string) $request->user()->email]);
    }

    /**
     * Change the email and, optionally, the password of the signed-in admin.
     *
     * A new password needs `current_password` and `password_confirmation`.
     *
     * @response array{data: array{email: string}, meta: object}
     */
    public function update(AccountUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->email = $request->validated('email');

        if ($request->filled('password')) {
            $user->password = $request->validated('password');
        }

        $user->save();

        return ApiResponse::success(['email' => (string) $user->email]);
    }
}
