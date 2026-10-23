<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ReservationImportPreview;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Column-mapping previews are part of importing (016): whoever may import
 * may upload, map, confirm and discard them.
 */
class ReservationImportPreviewPolicy
{
    use ChecksPermissions;

    /**
     * Super admins bypass every ability below.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_IMPORT);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::RESERVATIONS_IMPORT);
    }

    public function view(User $user, ReservationImportPreview $preview): Response|bool
    {
        return $this->allowsOwn($user, $preview);
    }

    public function delete(User $user, ReservationImportPreview $preview): Response|bool
    {
        return $this->allowsOwn($user, $preview);
    }

    /**
     * Another hotel's upload is not found rather than forbidden (FR-019):
     * its file holds that hotel's guest data, so not even its existence is
     * confirmed.
     */
    private function allowsOwn(User $user, ReservationImportPreview $preview): Response|bool
    {
        if ($preview->hotel_id !== $user->hotel?->id) {
            return Response::denyAsNotFound();
        }

        return $this->allows($user, Permission::RESERVATIONS_IMPORT, $preview);
    }
}
