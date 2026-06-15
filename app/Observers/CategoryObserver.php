<?php

namespace App\Observers;

use App\Models\Category;
use Illuminate\Support\Str;

class CategoryObserver
{
    public function creating(Category $category): void
    {
        if (empty($category->uuid)) {
            $category->uuid = (string) Str::uuid();
        }
        $category->created_by = auth('admin')->user()->uuid ?? null;
        $category->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function updating(Category $category): void
    {
        $category->updated_by = auth('admin')->user()->uuid ?? null;
    }
}
