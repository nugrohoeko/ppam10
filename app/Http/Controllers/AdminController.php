<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Rt;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    private function protectAdmin(): void
    {
        $admins = User::where('active', true)->with('role')->get()->filter(fn ($u) => $u->role?->slug === 'admin' && collect(['users.view', 'users.update', 'roles.view', 'roles.update', 'settings.update'])->every(fn ($p) => $u->hasPermission($p)));
        if ($admins->isEmpty()) {
            throw ValidationException::withMessages(['admin' => 'Harus ada setidaknya satu Admin aktif yang dapat mengelola pengguna, hak akses, dan pengaturan.']);
        }
    }

    public function users(Request $r)
    {
        $r->user()->requirePermission('users.view');

        return view('admin.users', ['users' => User::with(['role', 'rts'])->orderBy('name')->get(), 'roles' => Role::all(), 'rts' => Rt::orderBy('code')->get()]);
    }

    public function storeUser(Request $r)
    {
        $r->user()->requirePermission('users.create');

        return $this->saveUser($r);
    }

    public function updateUser(Request $r, User $user)
    {
        $r->user()->requirePermission('users.update');

        return $this->saveUser($r, $user);
    }

    private function saveUser(Request $r, ?User $user = null)
    {
        $data = $r->validate(['name' => 'required|string|max:120', 'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($user?->id)], 'password' => ($user ? 'nullable' : 'required').'|string|min:12', 'role_id' => 'required|exists:roles,id', 'active' => 'required|boolean', 'rt_ids' => 'nullable|array', 'rt_ids.*' => 'exists:rts,id']);
        if ($user && $user->active !== (bool) $data['active']) {
            $r->user()->requirePermission('users.deactivate');
        }
        DB::transaction(function () use ($user, $data) {
            Role::orderBy('id')->lockForUpdate()->get();
            User::orderBy('id')->lockForUpdate()->get();
            $before = $user?->only(['name', 'email', 'role_id', 'active']) ?? [];
            $ids = $data['rt_ids'] ?? [];
            unset($data['rt_ids']);
            if (empty($data['password'])) {
                unset($data['password']);
            }if ($user) {
                $user->update($data);
            } else {
                $user = User::create($data);
            }$user->rts()->sync($ids);
            $this->protectAdmin();
            AuditService::record('user.saved', $user, $before, $user->only(['name', 'email', 'role_id', 'active']));
        });

        return back()->with('success', 'Pengguna dan penugasan RT disimpan.');
    }

    public function roles(Request $r)
    {
        $r->user()->requirePermission('roles.view');

        return view('admin.roles', ['roles' => Role::all(), 'permissions' => Permissions::all()]);
    }

    public function updateRole(Request $r, Role $role)
    {
        $r->user()->requirePermission('roles.update');
        $data = $r->validate(['name' => 'required|string|max:120', 'permissions' => 'nullable|array', 'permissions.*' => Rule::in(array_keys(Permissions::all()))]);
        DB::transaction(function () use ($role, $data) {
            Role::orderBy('id')->lockForUpdate()->get();
            User::orderBy('id')->lockForUpdate()->get();
            $before = $role->toArray();
            $role->update(['name' => $data['name'], 'permissions' => $data['permissions'] ?? []]);
            $this->protectAdmin();
            AuditService::record('role.updated', $role, $before, $role->toArray());
        });

        return back()->with('success', 'Hak akses role diperbarui.');
    }

    public function regions(Request $r)
    {
        $r->user()->requirePermission('regions.view');

        return view('admin.regions', ['rts' => Rt::withCount('customers')->orderBy('code')->get()]);
    }

    public function storeRegion(Request $r)
    {
        $r->user()->requirePermission('regions.create');

        return $this->saveRegion($r);
    }

    public function updateRegion(Request $r, Rt $rt)
    {
        $r->user()->requirePermission('regions.update');
        if ($rt->active !== $r->boolean('active')) {
            $r->user()->requirePermission('regions.deactivate');
        }

return $this->saveRegion($r, $rt);
    }

    private function saveRegion(Request $r, ?Rt $rt = null)
    {
        $data = $r->validate(['code' => ['required', 'regex:/^[A-Za-z0-9.-]{1,12}$/', Rule::unique('rts')->ignore($rt?->id)], 'name' => 'required|string|max:100', 'rw' => 'required|string|max:12', 'active' => 'required|boolean']);
        $before = $rt?->toArray() ?? [];
        if ($rt) {
            $rt->update($data);
        } else {
            $rt = Rt::create($data);
        }AuditService::record('region.saved', $rt, $before, $rt->toArray());

        return back()->with('success', 'Wilayah disimpan.');
    }

    public function settings(Request $r)
    {
        $r->user()->requirePermission('settings.view');

        return view('admin.settings');
    }

    public function updateSettings(Request $r)
    {
        $r->user()->requirePermission('settings.update');
        $data = $r->validate(['pam_name' => 'required|string|max:120', 'pam_address' => 'nullable|string|max:1000', 'pam_contact' => 'nullable|string|max:160', 'pam_management' => 'nullable|string|max:1000', 'payment_info' => 'nullable|string|max:2000', 'invoice_note' => 'nullable|string|max:2000', 'receipt_note' => 'nullable|string|max:2000', 'logo' => 'nullable|image|mimes:png,jpeg,webp|max:2048', 'meter_precision' => 'required|integer|min:0|max:3', 'reading_verification' => 'required|boolean', 'expense_approval_required' => 'required|boolean', 'usage_spike_percent' => 'required|integer|min:1|max:10000', 'usage_drop_percent' => 'required|integer|min:1|max:100', 'payment_allocation_mode' => 'required|in:oldest,manual', 'inactive_policy' => 'required|in:block,allow', 'midperiod_policy' => 'required|in:full,prorate', 'default_due_day' => 'required|integer|min:1|max:28', 'label_width' => 'required|integer|min:35|max:190', 'label_height' => 'required|integer|min:20|max:120', 'label_margin' => 'required|integer|min:0|max:30', 'label_columns' => 'required|integer|min:1|max:5', 'label_paper' => 'required|in:A4,Letter', 'label_copies' => 'required|integer|min:1|max:20', 'number' => 'required|array:customer,invoice,receipt', 'number.customer' => 'required|array:prefix,digits', 'number.invoice' => 'required|array:prefix,digits', 'number.receipt' => 'required|array:prefix,digits', 'number.*.prefix' => ['required', 'string', 'max:30', 'regex:/\A(?:[A-Za-z0-9-]|\{YYYY\}|\{MM\})+\z/'], 'number.*.digits' => 'required|integer|min:4|max:12']);
        $paperWidth = $data['label_paper'] === 'Letter' ? 2159 : 2100;
        $paperHeight = $data['label_paper'] === 'Letter' ? 2794 : 2970;
        if ((($data['label_width'] * $data['label_columns']) + 2 * $data['label_margin']) * 10 > $paperWidth || ($data['label_height'] + 2 * $data['label_margin']) * 10 > $paperHeight) {
            throw ValidationException::withMessages(['label_width' => 'Ukuran label, margin, dan jumlah kolom melebihi area kertas yang dipilih.']);
        }
        DB::transaction(function () use ($r, $data) {
            $before = [];
            $after = [];
            foreach ($data as $key => $value) {
                if ($key === 'logo') {
                    continue;
                }if ($key === 'number') {
                    foreach (['customer', 'invoice', 'receipt'] as $type) {
                        foreach (['prefix', 'digits'] as $part) {
                            $setting = 'number.'.$type.'.'.$part;
                            $before[$setting] = SettingService::get($setting);
                            $after[$setting] = $value[$type][$part];
                            SettingService::set($setting, $after[$setting]);
                        }
                    }
                } else {
                    $key = ['label_width' => 'label_width_mm', 'label_height' => 'label_height_mm', 'label_margin' => 'label_margin_mm'][$key] ?? $key;
                    $before[$key] = SettingService::get($key);
                    $after[$key] = $value;
                    SettingService::set($key, $value);
                }
            }if ($r->hasFile('logo')) {
                SettingService::set('pam_logo', $r->file('logo')->store('branding', 'local'));
            }AuditService::record('settings.updated', null, $before, $after);
        });

        return back()->with('success', 'Pengaturan disimpan. Perubahan nomor hanya berlaku untuk identitas baru.');
    }

    public function audit(Request $r)
    {
        $r->user()->requirePermission('audit.view');
        $q = AuditLog::with('user');
        if ($r->filled('q')) {
            $q->where(fn ($q) => $q->where('action', 'like', '%'.$r->q.'%')->orWhere('reason', 'like', '%'.$r->q.'%'));
        }if ($r->filled('date_from')) {
            $q->whereDate('created_at', '>=', $r->date_from);
        }if ($r->filled('date_to')) {
            $q->whereDate('created_at', '<=', $r->date_to);
        }

return view('admin.audit',['logs' => $q->orderByDesc('id')->paginate(30)->withQueryString()]);
    }
}
