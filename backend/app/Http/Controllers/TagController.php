<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TagController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Tag::class);

        return TagResource::collection(
            Tag::query()->orderBy('name')->paginate($request->integer('per_page', 50))
        );
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $tag = Tag::create($request->validated());

        return (new TagResource($tag))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Tag $tag): TagResource
    {
        $this->authorize('view', $tag);

        return new TagResource($tag);
    }

    public function update(UpdateTagRequest $request, Tag $tag): TagResource
    {
        $this->authorize('update', $tag);

        $tag->update($request->validated());

        return new TagResource($tag);
    }

    public function destroy(Tag $tag): Response
    {
        $this->authorize('delete', $tag);

        $tag->delete();

        return response()->noContent();
    }
}
