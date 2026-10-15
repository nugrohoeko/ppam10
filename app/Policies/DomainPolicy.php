<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DomainPolicy
{
    private function resource(Model $model): string
    {
        return match (class_basename($model)) {
            'Customer' => 'customers','Reading' => 'readings','Invoice' => 'invoices','Payment' => 'payments','CashEntry' => 'finance',default => 'meters'
        };
    }

    public function view(User $user, Model $model): bool
    {
        $customer = $model instanceof Customer ? $model : ($model->customer ?? null);

        return $user->hasPermission($this->resource($model).'.view') && (! $customer || $user->canAccessCustomer($customer));
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model) && $user->hasPermission($this->resource($model).'.update');
    }
}
