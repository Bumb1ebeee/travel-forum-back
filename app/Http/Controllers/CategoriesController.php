<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Discussion;
use Illuminate\Http\Request;

class CategoriesController extends Controller
{
    public function getCategories()
    {
        $categories = Category::all();
        return response()->json(['categories' => $categories]);
    }

    public function discussionsByCategory(Request $request)
    {
        try {
            $categoryId = $request->input('category_id');

            $query = Discussion::query();

            if ($categoryId) {
                $query->where('category_id', $categoryId);
            }

            $discussions = $query->with(['user', 'media', 'tags'])->latest()->get();

            return response()->json(['discussions' => $discussions], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Ошибка загрузки обсуждений'], 500);
        }
    }

}
