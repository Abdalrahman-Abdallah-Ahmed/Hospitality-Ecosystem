<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\VoucherTemplate;
use App\Policies\Concerns\ChecksPermissions;

class VoucherTemplatePolicy
{
    use ChecksPermissions;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VOUCHER_TEMPLATES_VIEW);
    }

    public function view(User $user, VoucherTemplate $template): bool
    {
        return $this->allows($user, Permission::VOUCHER_TEMPLATES_VIEW, $template);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::VOUCHER_TEMPLATES_CREATE);
    }

    public function update(User $user, VoucherTemplate $template): bool
    {
        return $this->allows($user, Permission::VOUCHER_TEMPLATES_UPDATE, $template);
    }

    public function delete(User $user, VoucherTemplate $template): bool
    {
        return $this->allows($user, Permission::VOUCHER_TEMPLATES_DELETE, $template);
    }

    public function restore(User $user, VoucherTemplate $template): bool
    {
        return false;
    }

    public function forceDelete(User $user, VoucherTemplate $template): bool
    {
        return false;
    }
}
