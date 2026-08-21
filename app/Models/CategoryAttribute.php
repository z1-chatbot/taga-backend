<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Defines a field that applies to a category (and, by inheritance, its descendants).
 * A null category_id makes the attribute global.
 */
class CategoryAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'key',
        'label',
        'description',
        'type',
        'options',
        'unit',
        'placeholder',
        'is_required',
        'is_filterable',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_filterable' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    const TYPE_TEXT = 'text';
    const TYPE_TEXTAREA = 'textarea';
    const TYPE_NUMBER = 'number';
    const TYPE_SELECT = 'select';
    const TYPE_MULTISELECT = 'multiselect';
    const TYPE_BOOLEAN = 'boolean';
    const TYPE_DATE = 'date';

    public static function types(): array
    {
        return [
            self::TYPE_TEXT,
            self::TYPE_TEXTAREA,
            self::TYPE_NUMBER,
            self::TYPE_SELECT,
            self::TYPE_MULTISELECT,
            self::TYPE_BOOLEAN,
            self::TYPE_DATE,
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function values()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFilterable($query)
    {
        return $query->where('is_filterable', true);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('category_id');
    }

    /**
     * Laravel validation rules for this attribute, used when creating/updating a product.
     */
    public function validationRules(): array
    {
        $rules = [$this->is_required ? 'required' : 'nullable'];

        switch ($this->type) {
            case self::TYPE_NUMBER:
                $rules[] = 'numeric';
                break;
            case self::TYPE_BOOLEAN:
                $rules[] = 'boolean';
                break;
            case self::TYPE_DATE:
                $rules[] = 'date';
                break;
            case self::TYPE_SELECT:
                if (! empty($this->options)) {
                    $rules[] = 'in:' . implode(',', $this->options);
                }
                break;
            case self::TYPE_MULTISELECT:
                $rules[] = 'array';
                break;
            default:
                $rules[] = 'string';
                $rules[] = 'max:5000';
        }

        return $rules;
    }
}
