<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryAttribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin management of the per-category attribute definitions.
 *
 * This is what makes the catalogue extensible without a deploy: adding a field to a
 * category is a data change here, not a migration.
 */
class CategoryAttributeController extends Controller
{
    /**
     * Attributes for a category — its own plus everything inherited.
     */
    public function index($categoryId): JsonResponse
    {
        $category = Category::find($categoryId);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'own' => $category->attributes()->orderBy('sort_order')->get()
                    ->map(fn (CategoryAttribute $a) => $this->present($a, $category)),
                'resolved' => $category->resolvedAttributes()
                    ->map(fn (CategoryAttribute $a) => $this->present($a, $category))
                    ->values(),
            ],
        ]);
    }

    public function store(Request $request, $categoryId): JsonResponse
    {
        $category = Category::find($categoryId);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        $validated = $request->validate($this->rules($category->id));
        $validated['category_id'] = $category->id;

        $attribute = CategoryAttribute::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Attribute created successfully',
            'data' => $this->present($attribute, $category),
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $attribute = CategoryAttribute::find($id);

        if (! $attribute) {
            return response()->json([
                'success' => false,
                'message' => 'Attribute not found',
            ], 404);
        }

        $validated = $request->validate($this->rules($attribute->category_id, $attribute->id));

        $attribute->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Attribute updated successfully',
            'data' => $this->present($attribute->fresh()),
        ]);
    }

    /**
     * Deleting an attribute also removes every product value stored against it
     * (enforced by the FK cascade), so the count is surfaced first.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $attribute = CategoryAttribute::withCount('values')->find($id);

        if (! $attribute) {
            return response()->json([
                'success' => false,
                'message' => 'Attribute not found',
            ], 404);
        }

        if ($attribute->values_count > 0 && ! $request->boolean('force')) {
            return response()->json([
                'success' => false,
                'message' => "{$attribute->values_count} product(s) have a value for this attribute. "
                    . 'Re-send with force=true to delete it and those values.',
                'values_count' => $attribute->values_count,
            ], 422);
        }

        $attribute->delete();

        return response()->json([
            'success' => true,
            'message' => 'Attribute deleted successfully',
        ]);
    }

    private function rules(?int $categoryId, ?int $ignoreId = null): array
    {
        return [
            'key' => [
                $ignoreId ? 'sometimes' : 'required',
                'string',
                'max:100',
                // Machine name: safe to use as a form field and query parameter.
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('category_attributes', 'key')
                    ->where(fn ($q) => $q->where('category_id', $categoryId))
                    ->ignore($ignoreId),
            ],
            'label' => ($ignoreId ? 'sometimes|required' : 'required') . '|string|max:255',
            'description' => 'nullable|string|max:500',
            'type' => [$ignoreId ? 'sometimes' : 'required', Rule::in(CategoryAttribute::types())],
            // Options are only meaningful for the choice types.
            'options' => 'nullable|array|required_if:type,select,multiselect',
            'options.*' => 'string|max:255',
            'unit' => 'nullable|string|max:50',
            'placeholder' => 'nullable|string|max:255',
            'is_required' => 'nullable|boolean',
            'is_filterable' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ];
    }

    private function present(CategoryAttribute $attribute, ?Category $context = null): array
    {
        return [
            'id' => $attribute->id,
            'category_id' => $attribute->category_id,
            'key' => $attribute->key,
            'label' => $attribute->label,
            'description' => $attribute->description,
            'type' => $attribute->type,
            'options' => $attribute->options ?? [],
            'unit' => $attribute->unit,
            'placeholder' => $attribute->placeholder,
            'is_required' => $attribute->is_required,
            'is_filterable' => $attribute->is_filterable,
            'is_active' => $attribute->is_active,
            'sort_order' => $attribute->sort_order,
            'is_global' => $attribute->category_id === null,
            'inherited_from' => $context && $attribute->category_id !== null
                && $attribute->category_id !== $context->id
                    ? $attribute->category_id
                    : null,
        ];
    }
}
