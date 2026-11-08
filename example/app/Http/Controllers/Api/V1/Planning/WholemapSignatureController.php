<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\SignaturePasteRequest;
use App\Http\Requests\Api\V1\Planning\SignatureStoreRequest;
use App\Http\Requests\Api\V1\Planning\SignatureUpdateRequest;
use App\Http\Resources\V1\Planning\SignatureResource;
use App\Http\Responses\ApiResponse;
use App\Models\WholemapSignature;
use App\Services\Planning\WholemapWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Wormhole mapper signatures. Signatures belong to one user; nobody else can
 * see or change them (403 `forbidden`).
 */
class WholemapSignatureController extends Controller
{
    public function __construct(private WholemapWriter $writer) {}

    /**
     * Add a signature.
     *
     * @response array{data: SignatureResource, meta: object}
     */
    public function store(SignatureStoreRequest $request): JsonResponse
    {
        Gate::authorize('create', WholemapSignature::class);

        $signature = $this->writer->createSignature($request->user(), $request->validated());

        return ApiResponse::success(SignatureResource::make($signature), [], 201);
    }

    /**
     * Import pasted probe scanner rows.
     *
     * Creates signatures that are new in the system and updates the type and
     * name of existing ones. Locked signatures are skipped and not counted.
     * Rows with fewer than three tab-separated columns or an id longer than
     * seven characters are ignored.
     *
     * @response array{data: array{created: int, updated: int}, meta: object}
     */
    public function paste(SignaturePasteRequest $request): JsonResponse
    {
        Gate::authorize('create', WholemapSignature::class);

        $v = $request->validated();

        return ApiResponse::success($this->writer->pasteSignatures(
            $request->user(),
            (int) $v['system_id'],
            $v['system_name'],
            $v['paste'],
        ));
    }

    /**
     * Update a signature.
     *
     * Partial: only the fields sent change. A locked signature is refused with
     * 422 `validation_failed` until it is unlocked.
     *
     * @response array{data: SignatureResource, meta: object}
     */
    public function update(SignatureUpdateRequest $request, WholemapSignature $signature): JsonResponse
    {
        Gate::authorize('update', $signature);

        if ($signature->locked) {
            throw ValidationException::withMessages(['signature' => ['Signature is locked.']]);
        }

        return ApiResponse::success(SignatureResource::make(
            $this->writer->updateSignature($request->user(), $signature, $request->validated())
        ));
    }

    /**
     * Lock or unlock a signature.
     *
     * Flips the lock and returns the signature with its new state.
     *
     * @response array{data: SignatureResource, meta: object}
     */
    public function lockToggle(WholemapSignature $signature): JsonResponse
    {
        Gate::authorize('update', $signature);

        return ApiResponse::success(SignatureResource::make(
            $this->writer->toggleLock($signature)->load('wormholeConnection')
        ));
    }

    /**
     * Delete a signature (also when locked). Its connection goes with it.
     */
    public function destroy(WholemapSignature $signature): Response
    {
        Gate::authorize('delete', $signature);

        $this->writer->deleteSignature($signature);

        return response()->noContent();
    }
}
