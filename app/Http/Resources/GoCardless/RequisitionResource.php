<?php

declare(strict_types=1);

namespace App\Http\Resources\GoCardless;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Whitelists the GoCardless requisition payload sent to the frontend.
 *
 * The raw GoCardless API response includes sensitive/unused fields (ssn,
 * redirect, reference, account_selection, redirect_immediate) that must
 * never reach the client.
 *
 * The underlying resource is always a plain array: either a raw GoCardless API
 * payload (legacy passthrough) or an array projected from a
 * App\Models\GoCardlessRequisition row by the controller. Local-only keys
 * (local_status, status_label, access_valid_until, days_until_expiry,
 * needs_reconnect) are absent from the legacy shape and fall back to null.
 */
class RequisitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $id = data_get($this->resource, 'id');
        $id = is_string($id) ? $id : '';

        $status = data_get($this->resource, 'status');
        $status = is_string($status) ? $status : '';

        $accounts = data_get($this->resource, 'accounts', []);
        $accounts = is_array($accounts) ? $accounts : [];

        $daysUntilExpiry = data_get($this->resource, 'days_until_expiry');
        $rowId = data_get($this->resource, 'row_id');

        return [
            'id' => $id,
            // Local primary key: the reconnect endpoint binds on it, and it is the only stable
            // handle when the GoCardless id is absent from a legacy payload.
            'row_id' => is_int($rowId) ? $rowId : null,
            'created' => data_get($this->resource, 'created'),
            'status' => $status,
            'institution_id' => data_get($this->resource, 'institution_id'),
            'agreement' => data_get($this->resource, 'agreement'),
            'user_language' => data_get($this->resource, 'user_language'),
            'accounts' => $accounts,
            'link' => $status === 'LN' ? null : data_get($this->resource, 'link'),
            'local_status' => data_get($this->resource, 'local_status'),
            'status_label' => data_get($this->resource, 'status_label'),
            'access_valid_until' => data_get($this->resource, 'access_valid_until'),
            'days_until_expiry' => is_int($daysUntilExpiry) ? $daysUntilExpiry : null,
            'needs_reconnect' => (bool) data_get($this->resource, 'needs_reconnect', false),
        ];
    }
}
