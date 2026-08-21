<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Category tree.
     *
     * Defaults to the nested tree, which is what the storefront navigation wants.
     * `?flat=1` returns a flat list for pickers and dropdowns.
     *
     * `?include_inactive=1` also returns deactivated categories. The storefront must
     * never ask for this; the admin tree manager needs it, because a category that is
     * hidden from the tree the moment it is deactivated can never be found and
     * re-enabled again.
     */
    public function index(Request $request): JsonResponse
    {
        $includeInactive = $request->boolean('include_inactive');

        if ($request->boolean('flat')) {
            $categories = Category::query()
                ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
                ->withCount('products')
                ->orderBy('depth')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => $this->summarise($category));

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        }

        // One query per level via childrenRecursive, rather than per node.
        $tree = Category::whereNull('parent_id')
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->with('childrenRecursive')
            ->withCount('products')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $category) => $this->asNode($category, $includeInactive));

        return response()->json([
            'success' => true,
            'data' => $tree,
        ]);
    }

    /**
     * A single category, by id or slug, with its immediate children, the ancestor
     * trail for breadcrumbs, and the attributes that apply to it.
     */
    public function show($id): JsonResponse
    {
        $category = $this->resolve($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($this->summarise($category), [
                'children' => $category->children()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (Category $child) => $this->summarise($child)),
                // Reversed so breadcrumbs read root -> leaf.
                'breadcrumb' => $category->ancestors()
                    ->reverse()
                    ->values()
                    ->map(fn (Category $ancestor) => [
                        'id' => $ancestor->id,
                        'name' => $ancestor->name,
                        'slug' => $ancestor->slug,
                    ]),
                'attributes' => $category->resolvedAttributes()->map(fn ($attribute) => [
                    'key' => $attribute->key,
                    'label' => $attribute->label,
                    'description' => $attribute->description,
                    'type' => $attribute->type,
                    'options' => $attribute->options ?? [],
                    'unit' => $attribute->unit,
                    'placeholder' => $attribute->placeholder,
                    'is_required' => $attribute->is_required,
                    'is_filterable' => $attribute->is_filterable,
                    // Tells the admin UI where this attribute came from: defined here,
                    // inherited from a named ancestor, or global to every category.
                    'is_global' => $attribute->category_id === null,
                    'inherited_from' => $attribute->category_id === null
                        || $attribute->category_id === $category->id
                            ? null
                            : $attribute->category_id,
                ]),
            ]),
        ]);
    }

    /**
     * Products within a category, including everything in its subtree by default.
     */
    public function products($id, Request $request): JsonResponse
    {
        $category = $this->resolve($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        $query = Product::query()
            ->where('is_active', true)
            ->notExpired()
            ->with(['category', 'reviews']);

        $request->boolean('exact')
            ? $query->where('category_id', $category->id)
            : $query->inCategoryTree($category);

        if ($request->boolean('in_stock')) {
            $query->where('stock_quantity', '>', 0);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $this->summarise($category),
                'products' => $query->paginate($request->integer('per_page', 20)),
            ],
        ]);
    }

    /**
     * Admin: create a category.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $parent = isset($validated['parent_id']) ? Category::find($validated['parent_id']) : null;

        $validated['slug'] ??= $this->uniqueSlug($validated['name']);
        $validated['depth'] = $parent ? $parent->depth + 1 : 0;

        // Inherit regulatory defaults from the parent unless explicitly given.
        if ($parent) {
            $validated['product_type'] ??= $parent->product_type;
            $validated['requires_prescription'] ??= $parent->requires_prescription;
            $validated['is_controlled_substance'] ??= $parent->is_controlled_substance;
        }

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $this->summarise($category),
        ], 201);
    }

    /**
     * Admin: update a category, including reparenting it.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        $validated = $request->validate($this->rules($category->id));

        if (array_key_exists('parent_id', $validated)) {
            $newParentId = $validated['parent_id'];

            if ((int) $newParentId === (int) $category->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'A category cannot be its own parent.',
                ], 422);
            }

            // Reparenting under one's own descendant would detach the subtree from the
            // root and create a cycle.
            if ($newParentId && $this->descendantIds($category)->contains((int) $newParentId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A category cannot be moved beneath one of its own descendants.',
                ], 422);
            }

            $parent = $newParentId ? Category::find($newParentId) : null;
            $validated['depth'] = $parent ? $parent->depth + 1 : 0;
        }

        $category->update($validated);

        // Keep cached depths correct for everything underneath.
        if (array_key_exists('depth', $validated)) {
            $this->recalculateDepth($category);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $this->summarise($category->fresh()),
        ]);
    }

    /**
     * Admin: delete a category.
     *
     * Refuses while it still holds products or children, so a delete can never orphan
     * a subtree or silently strand listings.
     */
    public function destroy($id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found',
            ], 404);
        }

        $productCount = Product::where('category_id', $category->id)->count();

        if ($productCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: {$productCount} product(s) are still in this category. "
                    . 'Move them elsewhere first.',
            ], 422);
        }

        if ($category->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete: this category still has subcategories.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    private function rules(?int $ignoreId = null): array
    {
        $required = $ignoreId ? 'sometimes|required' : 'required';

        return [
            'name' => "{$required}|string|max:255",
            'slug' => 'nullable|string|max:255|unique:categories,slug' . ($ignoreId ? ",{$ignoreId}" : ''),
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'icon' => 'nullable|string|max:100',
            'parent_id' => 'nullable|exists:categories,id',
            'product_type' => 'nullable|in:medication,device,supply,wellness,general',
            'requires_prescription' => 'nullable|boolean',
            'is_controlled_substance' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
        ];
    }

    /**
     * Every descendant id, gathered one level at a time.
     */
    private function descendantIds(Category $category): \Illuminate\Support\Collection
    {
        $ids = collect();
        $frontier = [$category->id];

        while ($frontier) {
            $frontier = Category::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = $ids->merge($frontier);
        }

        return $ids;
    }

    /**
     * Rewrites cached depth for a subtree after a move.
     */
    private function recalculateDepth(Category $category): void
    {
        $level = [$category->id];
        $depth = $category->depth;

        while ($level) {
            $depth++;
            Category::whereIn('parent_id', $level)->update(['depth' => $depth]);
            $level = Category::whereIn('parent_id', $level)->pluck('id')->all();
        }
    }

    private function uniqueSlug(string $name): string
    {
        // Slug::make, not Str::slug: a name like "Cough/Cold/Flu" must not
        // collapse into one word. See App\Support\Slug.
        $base = \App\Support\Slug::make($name);
        $slug = $base;
        $suffix = 2;

        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Accepts a numeric id or a slug, including a slug whose separators have
     * since changed shape — see Category::findBySlugOrId().
     */
    private function resolve($id): ?Category
    {
        return Category::findBySlugOrId($id, activeOnly: true);
    }

    private function summarise(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'image' => $category->image,
            'icon' => $category->icon,
            'parent_id' => $category->parent_id,
            'depth' => $category->depth,
            'product_type' => $category->product_type,
            'requires_prescription' => $category->requires_prescription,
            'is_controlled_substance' => $category->is_controlled_substance,
            'sort_order' => $category->sort_order,
            'is_active' => $category->is_active,
            // Null on the single-category endpoints, which do not load the count.
            'products_count' => $category->products_count,
        ];
    }

    private function asNode(Category $category, bool $includeInactive = false): array
    {
        return array_merge($this->summarise($category), [
            'children' => $category->childrenRecursive
                ->when(! $includeInactive, fn ($c) => $c->where('is_active', true))
                ->map(fn (Category $child) => $this->asNode($child, $includeInactive))
                ->values(),
        ]);
    }
}
