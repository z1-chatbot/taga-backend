<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the PharmaMedSupport medication category blueprint.
 *
 * Structure per node:
 *   name, type (product_type), rx (requires_prescription), controlled, icon, children
 *
 * `rx` and `controlled` cascade to children unless a child overrides them, and become
 * the default for any product placed in that category.
 */
class PharmacyCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->blueprint() as $sortOrder => $node) {
            $this->createNode($node, null, 0, $sortOrder);
        }
    }

    private function createNode(array $node, ?int $parentId, int $depth, int $sortOrder): void
    {
        // Keyed on parent+name, not slug: the blueprint reuses some names across
        // branches (e.g. "Mental Health" under both Prescription Medicines and
        // Chronic Care), which are genuinely distinct categories.
        $category = Category::firstOrNew([
            'parent_id' => $parentId,
            'name' => $node['name'],
        ]);

        if (! $category->exists) {
            $category->slug = $this->uniqueSlug($node['name'], $parentId);
        }

        $category->fill([
            'depth' => $depth,
            'sort_order' => $sortOrder,
            'product_type' => $node['type'],
            'requires_prescription' => $node['rx'] ?? false,
            'is_controlled_substance' => $node['controlled'] ?? false,
            'icon' => $node['icon'] ?? null,
            'is_active' => true,
        ])->save();

        foreach ($node['children'] ?? [] as $childSort => $child) {
            // Children inherit Rx/controlled/type unless they set their own.
            $child['type'] ??= $node['type'];
            $child['rx'] ??= $node['rx'] ?? false;
            $child['controlled'] ??= $node['controlled'] ?? false;

            $this->createNode($child, $category->id, $depth + 1, $childSort);
        }
    }

    /**
     * Builds a slug that is unique across the whole tree, qualifying it with the parent's
     * slug when the plain form is already taken by a different branch.
     */
    private function uniqueSlug(string $name, ?int $parentId): string
    {
        // Slug::make treats "/" as a separator; Str::slug deletes it, which
        // turned Cough/Cold/Flu into "coughcoldflu".
        $base = \App\Support\Slug::make($name);

        if (! Category::where('slug', $base)->exists()) {
            return $base;
        }

        if ($parentId && $parentSlug = Category::whereKey($parentId)->value('slug')) {
            $qualified = \App\Support\Slug::make($parentSlug . '-' . $name);

            if (! Category::where('slug', $qualified)->exists()) {
                return $qualified;
            }
        }

        $suffix = 2;
        while (Category::where('slug', "{$base}-{$suffix}")->exists()) {
            $suffix++;
        }

        return "{$base}-{$suffix}";
    }

    /**
     * Leaf helper — a child with no further nesting.
     */
    private function leaf(string $name, array $overrides = []): array
    {
        return array_merge(['name' => $name], $overrides);
    }

    private function blueprint(): array
    {
        return [
            [
                'name' => 'Prescription Medicines (Rx)',
                'type' => 'medication',
                'rx' => true,
                'icon' => 'Pill',
                'children' => [
                    $this->leaf('Cardiovascular'),
                    $this->leaf('Endocrine/Diabetes'),
                    $this->leaf('Antibiotics & Antimicrobials'),
                    $this->leaf('Gastrointestinal'),
                    $this->leaf('Respiratory (Asthma/COPD)'),
                    $this->leaf('Neurology'),
                    $this->leaf('Mental Health'),
                    $this->leaf('Hormonal/Endocrine'),
                    $this->leaf('Pain Management (non-OTC)', ['controlled' => true]),
                ],
            ],
            [
                'name' => 'Over-The-Counter (OTC)',
                'type' => 'medication',
                'rx' => false,
                'icon' => 'Tablets',
                'children' => [
                    $this->leaf('Pain & Fever Relief'),
                    $this->leaf('Cough/Cold/Flu'),
                    $this->leaf('Allergies'),
                    $this->leaf('Digestive Health'),
                    $this->leaf('Eye/Ear/Nose Care'),
                    $this->leaf('First Aid'),
                    $this->leaf('Sleep Aids'),
                    $this->leaf('Vitamins & Supplements', ['type' => 'wellness']),
                ],
            ],
            [
                'name' => 'Chronic Care Medications',
                'type' => 'medication',
                'rx' => true,
                'icon' => 'HeartPulse',
                'children' => [
                    $this->leaf('Hypertension'),
                    $this->leaf('Diabetes'),
                    $this->leaf('Asthma/COPD'),
                    $this->leaf('Arthritis'),
                    $this->leaf('Epilepsy'),
                    $this->leaf('Mental Health'),
                    $this->leaf('Cholesterol Disorders'),
                ],
            ],
            [
                'name' => "Women's Health",
                'type' => 'medication',
                'rx' => false,
                'icon' => 'Venus',
                'children' => [
                    $this->leaf('Contraceptives', ['rx' => true]),
                    $this->leaf('Prenatal & Postnatal'),
                    $this->leaf('Hormonal Therapy', ['rx' => true]),
                    $this->leaf('Fertility Support'),
                    $this->leaf('Menstrual Care', ['type' => 'supply']),
                    $this->leaf('Vaginal Health'),
                ],
            ],
            [
                'name' => "Men's Health",
                'type' => 'medication',
                'rx' => false,
                'icon' => 'Mars',
                'children' => [
                    $this->leaf('Prostate Health', ['rx' => true]),
                    $this->leaf('Erectile Dysfunction', ['rx' => true]),
                    $this->leaf('Hormonal Support', ['rx' => true]),
                    $this->leaf('Sexual Vitality'),
                ],
            ],
            [
                'name' => 'Mother & Child Care',
                'type' => 'medication',
                'rx' => false,
                'icon' => 'Baby',
                'children' => [
                    $this->leaf('Infant Medicines'),
                    $this->leaf("Kids' Supplements", ['type' => 'wellness']),
                    $this->leaf('Baby Skin Care', ['type' => 'general']),
                    $this->leaf('Cough/Cold for Children'),
                    $this->leaf('ORS & Digestion'),
                    $this->leaf('Teething Relief'),
                ],
            ],
            [
                'name' => 'Vaccines & Immunization',
                'type' => 'medication',
                'rx' => true,
                'icon' => 'Syringe',
                'children' => [
                    $this->leaf('Childhood Vaccines'),
                    $this->leaf('Adult Vaccines'),
                    $this->leaf('Travel Vaccines'),
                    $this->leaf('Flu Shots'),
                ],
            ],
            [
                'name' => 'Sexual & Reproductive Health',
                'type' => 'medication',
                'rx' => false,
                'icon' => 'ShieldPlus',
                'children' => [
                    $this->leaf('STI Treatment', ['rx' => true]),
                    $this->leaf('Condoms & Protection', ['type' => 'supply']),
                    $this->leaf('Emergency Contraceptives'),
                    $this->leaf('Fertility Kits', ['type' => 'device']),
                    $this->leaf('Pregnancy Tests', ['type' => 'device']),
                ],
            ],
            [
                'name' => 'Wellness & Nutraceuticals',
                'type' => 'wellness',
                'rx' => false,
                'icon' => 'Leaf',
                'children' => [
                    $this->leaf('Immune Boosters'),
                    $this->leaf('Weight Management'),
                    $this->leaf('Herbal Remedies'),
                    $this->leaf('Fitness Supplements'),
                    $this->leaf('General Wellness'),
                ],
            ],
            [
                'name' => 'Dermatology',
                'type' => 'medication',
                'rx' => false,
                'icon' => 'Sparkles',
                'children' => [
                    $this->leaf('Acne'),
                    $this->leaf('Eczema'),
                    $this->leaf('Psoriasis', ['rx' => true]),
                    $this->leaf('Fungal Infections'),
                    $this->leaf('Skin Repair'),
                    $this->leaf('Dermocosmetics', ['type' => 'general']),
                ],
            ],
            [
                'name' => 'Personal Care & Hygiene',
                'type' => 'supply',
                'rx' => false,
                'icon' => 'Droplets',
                'children' => [
                    $this->leaf('Sanitary Pads'),
                    $this->leaf('Adult Diapers'),
                    $this->leaf('Oral Care'),
                    $this->leaf('Body Care'),
                    $this->leaf('Sanitizers'),
                    $this->leaf('Diagnostic Kits (BP Monitor, Glucometer)', ['type' => 'device']),
                ],
            ],
            [
                // Blueprint marks this "(No consumables)" — equipment, so no dosage,
                // strength, batch or expiry semantics apply.
                'name' => 'Medical Devices',
                'type' => 'device',
                'rx' => false,
                'icon' => 'Stethoscope',
                'children' => [
                    $this->leaf('Nebulizers'),
                    $this->leaf('Thermometers'),
                    $this->leaf('Glucometers & Strips'),
                    $this->leaf('First Aid Kits'),
                    $this->leaf('Home Diagnostic Devices'),
                ],
            ],
            [
                // The only three-level branch in the blueprint.
                'name' => 'Specialty & Critical Care Medications',
                'type' => 'medication',
                'rx' => true,
                'icon' => 'Activity',
                'children' => [
                    [
                        'name' => 'Oncology (Anti-Cancer)',
                        'children' => [
                            $this->leaf('Chemotherapy Agents'),
                            $this->leaf('Targeted Therapy'),
                            $this->leaf('Immunotherapy'),
                            $this->leaf('Oncology Supportive Care'),
                        ],
                    ],
                    [
                        'name' => 'ADHD & Neurodevelopmental',
                        'controlled' => true,
                        'children' => [
                            $this->leaf('Methylphenidate'),
                            $this->leaf('Atomoxetine', ['controlled' => false]),
                            $this->leaf('Other stimulants/non-stimulants'),
                        ],
                    ],
                    [
                        'name' => 'TPN & Specialized Nutrition',
                        'children' => [
                            $this->leaf('TPN Bags'),
                            $this->leaf('Amino Acids'),
                            $this->leaf('Electrolytes'),
                            $this->leaf('Lipid Emulsions'),
                            $this->leaf('Pediatric TPN'),
                        ],
                    ],
                    [
                        'name' => 'Critical Care & ICU Medications',
                        'children' => [
                            $this->leaf('Emergency Drugs'),
                            $this->leaf('IV Antibiotics'),
                            $this->leaf('Electrolyte Replacement'),
                            $this->leaf('Sedation & Analgesia', ['controlled' => true]),
                            $this->leaf('ICU-Level Therapies'),
                        ],
                    ],
                    [
                        'name' => 'Immunology & Rheumatology',
                        'children' => [
                            $this->leaf('Biologics'),
                            $this->leaf('Immunosuppressants'),
                        ],
                    ],
                    [
                        'name' => 'Nephrology',
                        'children' => [
                            $this->leaf('Dialysis Support Medications'),
                            $this->leaf('Erythropoietin'),
                            $this->leaf('Renal Supplements'),
                        ],
                    ],
                    [
                        'name' => 'Hepatology',
                        'children' => [
                            $this->leaf('Antivirals'),
                            $this->leaf('Liver Support Therapy'),
                        ],
                    ],
                    [
                        'name' => 'Endocrine & Hormonal Specialized',
                        'children' => [
                            $this->leaf('Thyroid Medications'),
                            $this->leaf('Growth Hormone'),
                            $this->leaf('Rare Endocrine Disorder Treatments'),
                        ],
                    ],
                ],
            ],
            [
                // Also marked "(No consumables)" in the blueprint.
                'name' => 'Hospital & Clinical Supplies',
                'type' => 'supply',
                'rx' => false,
                'icon' => 'Building2',
                'children' => [
                    $this->leaf('IV Fluids', ['type' => 'medication', 'rx' => true]),
                    $this->leaf('Injectable Medications', ['type' => 'medication', 'rx' => true]),
                    $this->leaf('Sterilization Supplies'),
                    $this->leaf('Lab Items (Non-consumable)', ['type' => 'device']),
                ],
            ],
        ];
    }
}
