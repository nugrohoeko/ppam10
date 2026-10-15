<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Rt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_role_creation_grants_migrate_without_granting_delete_to_other_roles(): void
    {
        $this->seed();
        $migration = require database_path('migrations/2026_10_15_000200_add_customer_soft_deletes_and_permissions.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('customers', 'deleted_at'));
        $creator = Role::create(['name' => 'Petugas pendaftaran', 'slug' => 'registration', 'permissions' => ['customers.view', 'customers.create', 'readings.view']]);
        $viewer = Role::create(['name' => 'Pemantau', 'slug' => 'observer', 'permissions' => ['customers.view']]);
        $migration->up();

        $this->assertTrue(Schema::hasColumn('customers', 'deleted_at'));
        $this->assertEqualsCanonicalizing(['customers.view', 'pelanggan.create', 'readings.view'], $creator->fresh()->permissions);
        $this->assertSame(['customers.view'], $viewer->fresh()->permissions);
        $this->assertContains('pelanggan.create', Role::where('slug', 'admin')->firstOrFail()->permissions);
        $this->assertContains('pelanggan.delete', Role::where('slug', 'admin')->firstOrFail()->permissions);
        foreach (Role::where('slug', '!=', 'admin')->get() as $role) {
            $this->assertNotContains('pelanggan.delete', $role->permissions);
            $this->assertNotContains('customers.create', $role->permissions);
        }
    }

    public function test_rollback_cannot_make_deleted_customer_visible_or_remove_new_permissions(): void
    {
        $this->seed();
        $customer = Customer::create(['number' => 'PAM10-TEST-ARCHIVE', 'name' => 'Pelanggan Arsip', 'address' => 'RW.10', 'rt_id' => Rt::firstOrFail()->id, 'category_id' => Category::firstOrFail()->id, 'status' => 'active', 'joined_on' => '2026-10-01']);
        $customer->delete();
        $permissions = Role::where('slug', 'admin')->firstOrFail()->permissions;
        $migration = require database_path('migrations/2026_10_15_000200_add_customer_soft_deletes_and_permissions.php');
        try {
            $migration->down();
            $this->fail('Rollback tidak boleh menghidupkan kembali pelanggan terhapus.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('pelanggan terhapus', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('customers', 'deleted_at'));
        $this->assertNull(Customer::find($customer->id));
        $this->assertTrue(Customer::withTrashed()->findOrFail($customer->id)->trashed());
        $this->assertSame($permissions, Role::where('slug', 'admin')->firstOrFail()->permissions);
    }
}
