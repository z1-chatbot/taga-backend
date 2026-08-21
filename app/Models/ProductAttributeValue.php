<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A product's value for one category attribute.
 *
 * Multiselect values are stored as JSON in `value`; numeric values are additionally
 * mirrored into `value_number` so range filters can use an index.
 */
class ProductAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'category_attribute_id',
        'value',
        'value_number',
    ];

    protected $casts = [
        'value_number' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute()
    {
        return $this->belongsTo(CategoryAttribute::class, 'category_attribute_id');
    }

    /**
     * Stores a raw input value, encoding it per the attribute's type and keeping
     * `value_number` in sync.
     */
    public function setFromInput(CategoryAttribute $attribute, mixed $input): static
    {
        if ($attribute->type === CategoryAttribute::TYPE_MULTISELECT) {
            $this->value = json_encode(array_values((array) $input));
            $this->value_number = null;

            return $this;
        }

        if ($attribute->type === CategoryAttribute::TYPE_BOOLEAN) {
            $this->value = filter_var($input, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            $this->value_number = null;

            return $this;
        }

        $this->value = $input === null ? null : (string) $input;
        $this->value_number = $attribute->type === CategoryAttribute::TYPE_NUMBER && is_numeric($input)
            ? (float) $input
            : null;

        return $this;
    }

    /**
     * Decodes the stored value back into its natural PHP type.
     */
    public function typedValue(?CategoryAttribute $attribute = null): mixed
    {
        $attribute ??= $this->attribute;

        return match ($attribute?->type) {
            CategoryAttribute::TYPE_MULTISELECT => json_decode($this->value ?? '[]', true) ?: [],
            CategoryAttribute::TYPE_BOOLEAN => (bool) $this->value,
            CategoryAttribute::TYPE_NUMBER => $this->value_number !== null
                ? (float) $this->value_number
                : (is_numeric($this->value) ? (float) $this->value : null),
            default => $this->value,
        };
    }
}
