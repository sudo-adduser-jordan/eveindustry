<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\SystemNoteRequest;
use App\Http\Resources\V1\Planning\SystemNoteResource;
use App\Http\Responses\ApiResponse;
use App\Models\WholemapSystemNote;
use App\Services\Planning\WholemapWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The user's own note on a system. One note per system.
 */
class WholemapNoteController extends Controller
{
    public function __construct(private WholemapWriter $writer) {}

    /**
     * Create or replace the note for a system.
     *
     * An upsert keyed on the user and `system_id`: 201 when the note is new,
     * 200 when it replaced an existing one.
     *
     * @response array{data: SystemNoteResource, meta: object}
     */
    public function upsert(SystemNoteRequest $request): JsonResponse
    {
        Gate::authorize('create', WholemapSystemNote::class);

        $note = $this->writer->upsertNote($request->user(), $request->validated());

        return ApiResponse::success(SystemNoteResource::make($note), [], $note->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Delete a note.
     */
    public function destroy(WholemapSystemNote $note): Response
    {
        Gate::authorize('delete', $note);

        $this->writer->deleteNote($note);

        return response()->noContent();
    }
}
