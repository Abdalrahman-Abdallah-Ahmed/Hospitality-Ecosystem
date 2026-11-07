<?php

namespace App\Http\Requests;

use App\Http\Requests\Generic\GenericIndexRequest;

/**
 * The tenant hotel list. An employee sees a short record, and can filter, sort
 * and search only on its fields: otherwise a hidden setting's value could be worked
 * out from the results.
 */
class HotelListRequest extends GenericIndexRequest
{
    /**
     * Fails closed: anyone but an admin or super admin gets the short record.
     * By role, because hotel settings are deliberately outside the permission
     * enum, so that no staff role can grant them.
     */
    public function showsFullRecords(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isAdmin() || $user->isSuperAdmin());
    }

    /**
     * @return array<int, string>|null
     */
    public function searchColumns(): ?array
    {
        return $this->showsFullRecords() ? null : ['name', 'slug', 'city'];
    }

    /**
     * @return array<int, string>
     */
    public function availableColumns(): array
    {
        return $this->showsFullRecords()
            ? parent::availableColumns()
            : ['id', 'name', 'slug', 'timezone', 'currency', 'city'];
    }
}
