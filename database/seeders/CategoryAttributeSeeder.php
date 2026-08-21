<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryAttribute;
use Illuminate\Database\Seeder;

/**
 * Seeds per-category attribute definitions.
 *
 * Note the split: fields that virtually every medicine has (strength, dosage_form,
 * pack_size, expiry_date, ...) are real columns on `products` because they are queried
 * and indexed constantly. Attributes here cover the fields that only SOME categories
 * need — which is exactly what the old fixed-column phone schema could not express.
 *
 * Attributes attach to a parent category and are inherited by every descendant.
 */
class CategoryAttributeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $categorySlug => $attributes) {
            // A null slug means a global attribute applying to every category.
            $categoryId = $categorySlug === '*'
                ? null
                : Category::where('slug', $categorySlug)->value('id');

            if ($categorySlug !== '*' && ! $categoryId) {
                continue;
            }

            foreach ($attributes as $sortOrder => $attribute) {
                CategoryAttribute::updateOrCreate(
                    ['category_id' => $categoryId, 'key' => $attribute['key']],
                    array_merge($attribute, [
                        'sort_order' => $sortOrder,
                        'is_active' => true,
                    ])
                );
            }
        }
    }

    private function definitions(): array
    {
        return [
            // ---- Applies everywhere -------------------------------------------
            '*' => [
                [
                    'key' => 'country_of_origin',
                    'label' => 'Country of Origin',
                    'type' => CategoryAttribute::TYPE_TEXT,
                    'is_filterable' => true,
                    'placeholder' => 'e.g. Nigeria',
                ],
            ],

            // ---- Devices: equipment concerns, no dosage ------------------------
            'medical-devices' => [
                [
                    'key' => 'warranty_period',
                    'label' => 'Warranty Period',
                    'type' => CategoryAttribute::TYPE_SELECT,
                    'options' => ['No warranty', '6 months', '1 year', '2 years', '3 years'],
                    'is_filterable' => true,
                ],
                [
                    'key' => 'power_source',
                    'label' => 'Power Source',
                    'type' => CategoryAttribute::TYPE_SELECT,
                    'options' => ['Battery', 'Mains', 'Rechargeable', 'Manual'],
                    'is_filterable' => true,
                ],
                [
                    'key' => 'calibration_required',
                    'label' => 'Requires Calibration',
                    'type' => CategoryAttribute::TYPE_BOOLEAN,
                ],
            ],

            // ---- Vaccines: cold chain ------------------------------------------
            'vaccines-immunization' => [
                [
                    'key' => 'storage_temperature',
                    'label' => 'Cold Chain Temperature',
                    'type' => CategoryAttribute::TYPE_SELECT,
                    'options' => ['2°C to 8°C', '-20°C', '-70°C', 'Room temperature'],
                    'is_required' => true,
                    'is_filterable' => true,
                ],
                [
                    'key' => 'doses_per_vial',
                    'label' => 'Doses per Vial',
                    'type' => CategoryAttribute::TYPE_NUMBER,
                    'unit' => 'doses',
                ],
                [
                    'key' => 'minimum_age',
                    'label' => 'Minimum Age',
                    'type' => CategoryAttribute::TYPE_TEXT,
                    'placeholder' => 'e.g. 6 weeks',
                ],
            ],

            // ---- Dermatology ----------------------------------------------------
            'dermatology' => [
                [
                    'key' => 'skin_type',
                    'label' => 'Suitable Skin Type',
                    'type' => CategoryAttribute::TYPE_MULTISELECT,
                    'options' => ['Normal', 'Dry', 'Oily', 'Combination', 'Sensitive'],
                    'is_filterable' => true,
                ],
                [
                    'key' => 'spf',
                    'label' => 'SPF',
                    'type' => CategoryAttribute::TYPE_NUMBER,
                    'is_filterable' => true,
                ],
            ],

            // ---- Wellness / supplements -----------------------------------------
            'wellness-nutraceuticals' => [
                [
                    'key' => 'serving_size',
                    'label' => 'Serving Size',
                    'type' => CategoryAttribute::TYPE_TEXT,
                    'placeholder' => 'e.g. 2 capsules daily',
                ],
                [
                    'key' => 'flavour',
                    'label' => 'Flavour',
                    'type' => CategoryAttribute::TYPE_TEXT,
                    'is_filterable' => true,
                ],
                [
                    'key' => 'dietary_suitability',
                    'label' => 'Dietary Suitability',
                    'type' => CategoryAttribute::TYPE_MULTISELECT,
                    'options' => ['Vegan', 'Vegetarian', 'Halal', 'Gluten-free', 'Sugar-free'],
                    'is_filterable' => true,
                ],
            ],

            // ---- Personal care ---------------------------------------------------
            'personal-care-hygiene' => [
                [
                    'key' => 'pack_count',
                    'label' => 'Items per Pack',
                    'type' => CategoryAttribute::TYPE_NUMBER,
                    'is_filterable' => true,
                ],
                [
                    'key' => 'absorbency',
                    'label' => 'Absorbency',
                    'type' => CategoryAttribute::TYPE_SELECT,
                    'options' => ['Light', 'Regular', 'Heavy', 'Overnight'],
                    'is_filterable' => true,
                ],
            ],

            // ---- Mother & child ---------------------------------------------------
            'mother-child-care' => [
                [
                    'key' => 'age_range',
                    'label' => 'Suitable Age Range',
                    'type' => CategoryAttribute::TYPE_SELECT,
                    'options' => ['0-6 months', '6-12 months', '1-3 years', '3-6 years', '6-12 years'],
                    'is_filterable' => true,
                ],
            ],

            // ---- Cold-chain specialty ----------------------------------------------
            'specialty-critical-care-medications' => [
                [
                    'key' => 'requires_cold_chain',
                    'label' => 'Requires Cold Chain Delivery',
                    'type' => CategoryAttribute::TYPE_BOOLEAN,
                    'description' => 'Flags orders needing temperature-controlled delivery.',
                ],
                [
                    'key' => 'administration_setting',
                    'label' => 'Administration Setting',
                    'type' => CategoryAttribute::TYPE_SELECT,
                    'options' => ['Home', 'Clinic', 'Hospital only', 'ICU only'],
                    'is_filterable' => true,
                ],
            ],
        ];
    }
}
