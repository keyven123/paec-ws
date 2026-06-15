<?php

namespace App\Http\Repositories;

use App\Exceptions\NoCategoryFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\Category;
use App\Helpers\GeneralHelper;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CategoryRepository
{
    /**
     * @param Category $category
     */
    public function __construct(protected Category $category)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->category->filters($filters)
            ->orderBy('name', 'asc');
    }

    /**
     * Fetch category or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return Category
     * @throws NoCategoryFoundException
     */
    public function fetchOrThrow(string $key, string $value): Category
    {
        $category = $this->category->where($key, $value)->first();

        if (is_null($category)) {
            throw new NoCategoryFoundException();
        }

        return $category;
    }

    /**
     * @param array $payload
     * @return Category
     */
    public function create(array $payload): Category
    {
        $categoryPayload = GeneralHelper::unsetUnknownAndNullFields($payload, Category::DATA);
        $categoryPayload['code'] = Str::slug($categoryPayload['name']);
        return $this->category->create($categoryPayload);
    }

    /**
     * @param Category $category
     * @param array $payload
     * @return bool|Category
     */
    public function update(Category $category, array $payload): bool|Category
    {
        $categoryPayload = GeneralHelper::unsetUnknownAndNullFields($payload, Category::DATA);
        $categoryPayload['code'] = Str::slug($categoryPayload['name']);
        return $category->update($categoryPayload);
    }

    /**
     * @param Category $category
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(Category $category): void
    {
        // Add any business logic for deletion here
        // For example, prevent deletion if category is in use
        if ($category->events()->count() > 0) {
            throw new UnauthorizedException('Cannot delete category that is being used by events.');
        }

        $category->delete();
    }
}
