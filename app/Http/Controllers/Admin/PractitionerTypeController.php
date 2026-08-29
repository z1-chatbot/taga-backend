<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PractitionerType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The practitioner specialties a shopper can ask for.
 *
 * Platform admins only. The storefront reads the active ones through
 * ConsultationController::practitionerTypes().
 */
class PractitionerTypeController extends Controller
{
    private function rules($id = null): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            // Editable, but it is what consultations store, so changing one
            // orphans the label on every consultation that named it. The admin
            // page does not offer it; this is here for completeness.
            'slug' => [
                'sometimes',
                'string',
                'max:60',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('practitioner_types', 'slug')->ignore($id),
            ],
        ];
    }

    private function messages(): array
    {
        return [
            'slug.regex' => 'A slug may use lowercase letters, numbers and underscores only.',
        ];
    }

    public function index(): JsonResponse
    {
        $types = PractitionerType::ordered()->get();

        // Whether each is in use, so the page can explain why one cannot be
        // deleted before the administrator tries it.
        $types->each(fn (PractitionerType $type) => $type->setAttribute('in_use', $type->isInUse()));

        return response()->json(['success' => true, 'data' => $types]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(), $this->messages());

        // Derived from the label unless one was given: an administrator adding
        // "Counsellor" should not have to know what a slug is.
        $validated['slug'] = $validated['slug'] ?? $this->uniqueSlug($validated['label']);

        return response()->json([
            'success' => true,
            'message' => 'Specialty added.',
            'data' => PractitionerType::create($validated),
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $type = PractitionerType::find($id);

        if (! $type) {
            return response()->json(['success' => false, 'message' => 'Specialty not found'], 404);
        }

        $type->update($request->validate($this->rules($id), $this->messages()));

        return response()->json([
            'success' => true,
            'message' => 'Specialty updated.',
            'data' => $type->fresh(),
        ]);
    }

    public function toggleStatus($id): JsonResponse
    {
        $type = PractitionerType::find($id);

        if (! $type) {
            return response()->json(['success' => false, 'message' => 'Specialty not found'], 404);
        }

        $type->update(['is_active' => ! $type->is_active]);

        return response()->json([
            'success' => true,
            'message' => $type->is_active
                ? 'Shoppers can ask for this specialty again.'
                : 'Shoppers can no longer ask for this specialty.',
            'data' => $type,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $type = PractitionerType::find($id);

        if (! $type) {
            return response()->json(['success' => false, 'message' => 'Specialty not found'], 404);
        }

        /*
         * Deactivate rather than delete once anyone has asked for it.
         *
         * Consultations store the slug as a record of what was asked for.
         * Deleting the row would leave those reading as a tidied-up slug for
         * ever, and the request itself is not undone by withdrawing the
         * specialty. Hiding it stops new requests, which is what deleting was
         * for.
         */
        if ($type->isInUse()) {
            $type->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => "Consultations have already asked for {$type->label}, so it has been hidden "
                    .'rather than deleted — those records keep their label.',
                'data' => $type->fresh(),
            ]);
        }

        $type->delete();

        return response()->json(['success' => true, 'message' => 'Specialty removed.']);
    }

    private function uniqueSlug(string $label): string
    {
        $base = Str::snake(Str::lower(trim($label)));
        $base = preg_replace('/[^a-z0-9_]+/', '_', $base) ?: 'specialty';
        $base = trim($base, '_');

        $slug = $base;
        $suffix = 2;

        while (PractitionerType::where('slug', $slug)->exists()) {
            $slug = $base.'_'.$suffix++;
        }

        return $slug;
    }
}
