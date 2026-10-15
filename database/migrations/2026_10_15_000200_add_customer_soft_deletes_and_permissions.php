<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes()->index();
        });

        // Preserve existing creation grants while moving to the requested keys.
        // Deletion is a separate new grant, initially assigned only to Admin.
        DB::table('roles')->orderBy('id')->each(function ($role) {
            $permissions = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
            $permissions = array_map(fn ($permission) => $permission === 'customers.create' ? 'pelanggan.create' : $permission, $permissions);
            if ($role->slug === 'admin') {
                $permissions[] = 'pelanggan.delete';
            }
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Refuse a rollback that would make archived customers visible again.
        if (DB::table('customers')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Migration tidak dapat dibatalkan selama ada pelanggan terhapus. Pulihkan data dengan prosedur yang disetujui terlebih dahulu.');
        }
        DB::table('roles')->orderBy('id')->each(function ($role) {
            $permissions = json_decode($role->permissions, true, 512, JSON_THROW_ON_ERROR);
            $permissions = array_values(array_filter(array_map(fn ($permission) => $permission === 'pelanggan.create' ? 'customers.create' : $permission, $permissions), fn ($permission) => $permission !== 'pelanggan.delete'));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
