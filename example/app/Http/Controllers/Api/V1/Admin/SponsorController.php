<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SponsorStoreRequest;
use App\Http\Requests\Api\V1\Admin\SponsorUpdateRequest;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Admin\SponsorResource;
use App\Http\Responses\ApiResponse;
use App\Models\Sponsor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SponsorController extends Controller
{
    /**
     * List sponsors, in display order (not paginated).
     *
     * @response array{data: SponsorResource[], meta: object}
     */
    public function index(NoInputRequest $request): JsonResponse
    {
        return ApiResponse::success(SponsorResource::collection(Sponsor::ordered()->get()));
    }

    /**
     * One sponsor.
     *
     * @response array{data: SponsorResource, meta: object}
     */
    public function show(NoInputRequest $request, Sponsor $sponsor): JsonResponse
    {
        return ApiResponse::success(new SponsorResource($sponsor));
    }

    /**
     * Add a sponsor with its banner (multipart form).
     *
     * @status 201
     *
     * @response array{data: SponsorResource, meta: object}
     */
    public function store(SponsorStoreRequest $request): JsonResponse
    {
        $sponsor = Sponsor::create([
            'name' => $request->validated('name'),
            'url' => $request->validated('url'),
            'image_path' => $request->file('image')->store('sponsors', config('filesystems.uploads')),
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => $request->validated('sort_order') ?? 0,
        ]);

        return ApiResponse::success(new SponsorResource($sponsor), status: 201);
    }

    /**
     * Change a sponsor (multipart form, sent with POST). A new banner replaces the old one.
     *
     * @response array{data: SponsorResource, meta: object}
     */
    public function update(SponsorUpdateRequest $request, Sponsor $sponsor): JsonResponse
    {
        $data = [
            'name' => $request->validated('name'),
            'url' => $request->validated('url'),
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => $request->validated('sort_order') ?? 0,
        ];

        if ($request->hasFile('image')) {
            Sponsor::imageDisk()->delete($sponsor->image_path);
            $data['image_path'] = $request->file('image')->store('sponsors', config('filesystems.uploads'));
        }

        $sponsor->update($data);

        return ApiResponse::success(new SponsorResource($sponsor));
    }

    /**
     * Delete a sponsor and its banner.
     */
    public function destroy(NoInputRequest $request, Sponsor $sponsor): Response
    {
        Sponsor::imageDisk()->delete($sponsor->image_path);
        $sponsor->delete();

        return response()->noContent();
    }
}
