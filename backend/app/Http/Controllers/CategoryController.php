<?php

namespace App\Http\Controllers;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);

        $query = Category::query()->orderBy('sort_order')->orderBy('name');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->has('archived')) {
            $query->where('is_archived', $request->boolean('archived'));
        }

        if ($request->has('parent_id')) {
            $parent = $request->input('parent_id');
            $query->where('parent_id', $parent === null || $parent === '' ? null : $parent);
        }

        return CategoryResource::collection(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $category = Category::create($request->validated());

        return (new CategoryResource($category))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Category $category): CategoryResource
    {
        $this->authorize('view', $category);

        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $this->authorize('update', $category);

        $category->update($request->validated());

        return new CategoryResource($category);
    }

    public function destroy(Category $category): Response
    {
        $this->authorize('delete', $category);

        $category->delete();

        return response()->noContent();
    }
}
