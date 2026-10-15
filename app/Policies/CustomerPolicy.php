<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy extends DomainPolicy
{
    public function create(User $user): bool
    {
        return $user->fresh(['role'])?->hasPermission('pelanggan.create') ?? false;
    }

    public function delete(User $user, Customer $customer): bool
    {
        $user = $user->fresh(['role']);

        return $user !== null && $user->hasPermission('pelanggan.delete')
            && ! $customer->trashed()
            && $user->canAccessCustomer($customer);
    }
}
