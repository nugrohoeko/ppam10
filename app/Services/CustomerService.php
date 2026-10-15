<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerHistory;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Period;
use App\Models\Rt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function save(User $actor, array $data, ?Customer $customer = null): Customer
    {
        if ($customer) {
            $actor->requirePermission('customers.update');
        } else {
            Gate::forUser($actor)->authorize('create', Customer::class);
        }
        if ($customer) {
            abort_unless($actor->canAccessCustomer($customer), 403);
        }
        $data = Validator::make($data, ['name' => 'required|string|max:160', 'address' => 'required|string|max:2000', 'rt_id' => 'required|exists:rts,id', 'category_id' => 'required|exists:categories,id', 'phone' => 'nullable|string|max:40', 'status' => 'required|in:active,inactive,disconnected', 'joined_on' => 'required|date', 'location' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:4000', 'photo_path' => 'nullable|string|max:255', 'reason' => $customer ? 'required|string|min:5|max:2000' : 'nullable|string', 'serial' => $customer ? 'nullable' : 'required|string|max:120|unique:meters,serial', 'initial_reading' => $customer ? 'nullable' : 'required|regex:/^\d{1,10}(\.\d{1,3})?$/', 'installed_on' => $customer ? 'nullable' : 'required|date'])->validate();
        if ($customer && $customer->status !== $data['status']) {
            $actor->requirePermission('customers.deactivate');
        }
        if ($actor->role?->slug === 'operator') {
            abort_unless($actor->rts()->where('rts.id', $data['rt_id'])->exists(), 403);
        }

        return DB::transaction(function () use ($actor, $data, $customer) {
            if ($customer) {
                $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->canAccessCustomer($customer), 403);
                if ($customer->status !== $data['status']) {
                    $actor->requirePermission('customers.deactivate');
                }
            }
            $rt = Rt::whereKey($data['rt_id'])->lockForUpdate()->firstOrFail();
            $category = Category::whereKey($data['category_id'])->lockForUpdate()->firstOrFail();
            if (! $rt->active && (! $customer || (int) $customer->rt_id !== (int) $rt->id)) {
                throw ValidationException::withMessages(['rt_id' => 'Wilayah tujuan baru harus aktif. Wilayah lama yang diarsipkan dapat dipertahankan.']);
            }
            if (! $category->active && (! $customer || (int) $customer->category_id !== (int) $category->id)) {
                throw ValidationException::withMessages(['category_id' => 'Kategori tujuan baru harus aktif. Kategori lama yang diarsipkan dapat dipertahankan.']);
            }
            $before = $customer?->toArray() ?? [];
            $fields = collect($data)->except(['reason', 'serial', 'initial_reading', 'installed_on'])->all();
            if (! $customer) {
                $initial = Decimal::normalize((string) $data['initial_reading'], (int) SettingService::get('meter_precision', 3));
                $customer = Customer::create([...$fields, 'number' => NumberService::next('customer')]);
                Meter::create(['customer_id' => $customer->id, 'serial' => $data['serial'], 'initial_reading' => $initial, 'installed_on' => $data['installed_on'], 'active' => true]);
            } else {
                $customer->update($fields);
            }
            CustomerHistory::create(['customer_id' => $customer->id, 'user_id' => $actor->id, 'before' => $before, 'after' => $customer->fresh()->toArray(), 'reason' => $data['reason'] ?? 'Pendaftaran pelanggan']);
            AuditService::record($before ? 'customer.updated' : 'customer.created', $customer, $before, $customer->toArray(), $data['reason'] ?? null, $actor);

            return $customer;
        }, 3);
    }

    public function delete(User $actor, Customer $customer, string $reason): Customer
    {
        Gate::forUser($actor)->authorize('delete', $customer);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $customer, $reason) {
            // Payments, invoice issuance and reversals take period locks before
            // customer/invoice locks too, so debt cannot change during this check.
            Period::orderBy('id')->lockForUpdate()->get();
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $customer);
            $invoices = Invoice::where('customer_id', $customer->id)
                ->whereIn('status', ['issued', 'partial'])
                ->orderBy('id')->lockForUpdate()->get();
            $outstanding = $invoices->sum(fn ($invoice) => max(0, (int) $invoice->total - (int) $invoice->paid_amount));
            if ($outstanding > 0) {
                throw ValidationException::withMessages([
                    'customer_deletion' => 'Pelanggan '.$customer->number.' — '.$customer->name.' tidak dapat dihapus karena masih memiliki tagihan belum lunas sebesar Rp'.number_format($outstanding, 0, ',', '.').'. Lunasi seluruh tagihan terlebih dahulu.',
                ]);
            }

            $before = $customer->toArray();
            $customer->status = 'inactive';
            $customer->save();
            $customer->delete();
            $after = $customer->toArray();
            CustomerHistory::create([
                'customer_id' => $customer->id, 'user_id' => $actor->id,
                'before' => $before, 'after' => $after, 'reason' => $reason,
            ]);
            AuditService::record('customer.deleted', $customer, $before, [
                ...$after, 'deleted_by' => ['id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
            ], $reason, $actor);

            return $customer;
        }, 3);
    }
}
