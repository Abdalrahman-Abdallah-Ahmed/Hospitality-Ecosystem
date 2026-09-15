<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\StaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Store and update rules for a staff role. The generic requests only know
 * `permissions` is an array; this checks every entry is a grantable
 * permission and that the name is free within the role's hotel.
 */
class StaffRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $staffRole = $this->route('staff_role');
        $updating = $staffRole instanceof StaffRole;

        return [
            'hotel_id' => ['nullable', 'uuid'],
            'name' => [
                $updating ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('staff_roles', 'name')
                    ->where('hotel_id', $updating ? $staffRole->hotel_id : $this->targetHotelId())
                    ->withoutTrashed()
                    ->ignore($updating ? $staffRole->getKey() : null),
            ],
            'description' => ['nullable', 'string'],
            // `present` rather than `required`: a role with no permissions is a
            // legitimate way to take an employee's access away.
            'permissions' => [$updating ? 'sometimes' : 'present', 'array', 'list'],
            'permissions.*' => ['string', 'distinct', Rule::enum(Permission::class)],
        ];
    }

    /**
     * The hotel a new role will belong to, as the controller resolves it. A
     * malformed hotel_id is left to its own rule rather than sent to a uuid
     * column, which Postgres would reject with a query error.
     */
    private function targetHotelId(): ?string
    {
        $requested = $this->input('hotel_id');

        return resolveHotel(
            $this->user(),
            is_string($requested) && Str::isUuid($requested) ? $requested : null,
        )?->id;
    }
}
