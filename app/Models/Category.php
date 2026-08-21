<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'image',
        'icon',
        'parent_id',
        'depth',
        'sort_order',
        'is_active',
        'product_type',
        'requires_prescription',
        'is_controlled_substance',
        'meta_title',
        'meta_description'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_prescription' => 'boolean',
        'is_controlled_substance' => 'boolean',
        'depth' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    // Product types drive which attributes and validation rules apply.
    const TYPE_MEDICATION = 'medication';
    const TYPE_DEVICE = 'device';
    const TYPE_SUPPLY = 'supply';
    const TYPE_WELLNESS = 'wellness';
    const TYPE_GENERAL = 'general';

    /**
     * Product types that carry dosage, batch and expiry semantics. The blueprint's
     * "(No consumables)" groups (devices, supplies) deliberately do not.
     */
    public static function dosableTypes(): array
    {
        return [self::TYPE_MEDICATION, self::TYPE_WELLNESS];
    }

    public function isDosable(): bool
    {
        return in_array($this->product_type, self::dosableTypes(), true);
    }

    // Relationships
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Eager-loadable recursive children, for rendering the whole tree in one go.
     */
    public function childrenRecursive()
    {
        return $this->children()
            ->with('childrenRecursive')
            ->withCount('products')
            ->orderBy('sort_order');
    }

    public function attributes()
    {
        return $this->hasMany(CategoryAttribute::class);
    }

    /**
     * Resolve a category from a route value: a numeric id, or a slug.
     *
     * The slug lookup falls back to a separator-insensitive match, so a link
     * built before the slug rule changed still lands on the right category —
     * /products?category=coughcoldflu finds cough-cold-flu. Exact matches
     * always win, so this can only rescue a URL that would otherwise 404.
     */
    public static function findBySlugOrId($value, bool $activeOnly = false): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $base = static::query()->when($activeOnly, fn ($q) => $q->where('is_active', true));

        if (is_numeric($value)) {
            return $base->find($value);
        }

        $slug = (string) $value;

        if ($exact = (clone $base)->where('slug', $slug)->first()) {
            return $exact;
        }

        $compact = \App\Support\Slug::compact($slug);

        if ($compact === '') {
            return null;
        }

        return $base->whereRaw("REPLACE(slug, '-', '') = ?", [$compact])->first();
    }

    /**
     * This category plus every ancestor, nearest first.
     */
    public function ancestors(): \Illuminate\Support\Collection
    {
        $chain = collect();
        $node = $this->parent;

        // Guarded against a cyclic parent_id rather than looping forever.
        $seen = [$this->id];
        while ($node && ! in_array($node->id, $seen, true)) {
            $chain->push($node);
            $seen[] = $node->id;
            $node = $node->parent;
        }

        return $chain;
    }

    /**
     * Every attribute that applies here: this category's own, plus those inherited from
     * ancestors, plus globals (category_id = null). Nearer definitions win on key
     * collision, so a child can override an inherited attribute.
     */
    public function resolvedAttributes(): \Illuminate\Support\Collection
    {
        $ids = $this->ancestors()->pluck('id')->push($this->id);

        return CategoryAttribute::query()
            ->active()
            ->where(fn ($q) => $q->whereIn('category_id', $ids)->orWhereNull('category_id'))
            ->orderBy('sort_order')
            ->get()
            // Distance from this category: own = 0, each ancestor step higher, global last.
            ->sortBy(function (CategoryAttribute $attribute) use ($ids) {
                if ($attribute->category_id === null) {
                    return PHP_INT_MAX;
                }

                return $ids->count() - 1 - $ids->search($attribute->category_id);
            })
            ->unique('key')
            ->sortBy('sort_order')
            ->values();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeParent($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeChild($query)
    {
        return $query->whereNotNull('parent_id');
    }

    // Accessors
    public function getProductCountAttribute()
    {
        return $this->products()->count();
    }

    public function getActiveProductCountAttribute()
    {
        return $this->products()->active()->count();
    }
}
