<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Prescription;
use Illuminate\Http\Request;

/**
 * Resolves a prescription the caller is entitled to buy against.
 *
 * Both the cart and buy-now accept a prescription id from the request body.
 * Without this check either one lets a shopper quote someone else's approved
 * script — a stranger's id is all it takes to buy prescription-only medicine,
 * and the record ids are sequential.
 *
 * Guests can upload before they have an account, so a script is owned either by
 * the account that uploaded it or by the guest id it was uploaded under.
 */
trait ResolvesOwnPrescription
{
    protected function resolveOwnPrescription(Request $request, $prescriptionId): ?Prescription
    {
        if (! $prescriptionId) {
            return null;
        }

        $prescription = Prescription::find($prescriptionId);

        if (! $prescription) {
            return null;
        }

        $user = $request->user();

        if ($user && $prescription->user_id && (int) $prescription->user_id === (int) $user->id) {
            return $prescription;
        }

        $guestId = $request->header('X-Guest-ID');

        if ($guestId
            && $prescription->session_id
            && hash_equals((string) $prescription->session_id, (string) $guestId)) {
            return $prescription;
        }

        return null;
    }
}
