<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Repositories\CategoryRepository;
use App\Http\Requests\Category\CreateCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Requests\Category\ListCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Exceptions\NoCategoryFoundException;
use App\Exceptions\UnauthorizedException;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(protected CategoryRepository $categoryRepository)
    {
    }

    /**
     * Display a listing of categories.
     * @param ListCategoryRequest $request
     * @return JsonResponse
     */
    public function index(ListCategoryRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->categoryRepository->getAll($request->validated());
        return CategoryResource::collection($list->paginate($perPage))->response();
    }

    public function publicCategories(ListCategoryRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['status'] = GeneralConstants::GENERAL_STATUSES['ACTIVE'];
        $list = $this->categoryRepository->getAll($payload);
        return CategoryResource::collection($list->get())->response();
    }

    /**
     * Store a newly created category.
     * @param CreateCategoryRequest $request
     * @return JsonResponse
     */
    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $category = $this->categoryRepository->create($payload);
        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    /**
     * Display the specified category.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $category = $this->categoryRepository->fetchOrThrow('uuid', $uuid);
            return (new CategoryResource($category))->response();
        } catch (NoCategoryFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }
    }

    /**
     * Update the specified category.
     * @param UpdateCategoryRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateCategoryRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $category = $this->categoryRepository->fetchOrThrow('uuid', $uuid);
            $this->categoryRepository->update($category, $payload);
            return (new CategoryResource($category->fresh()))->response();
        } catch (NoCategoryFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }
    }

    /**
     * Remove the specified category from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $category = $this->categoryRepository->fetchOrThrow('uuid', $uuid);
            $this->categoryRepository->delete($category);
            return $this->noContent();
        } catch (NoCategoryFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }
}
