<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Reading;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrivateFileController extends Controller
{
    public function show(Request $r, string $type, int $id = 0)
    {
        if ($type === 'logo') {
            $path = SettingService::get('pam_logo');
        } elseif ($type === 'customer') {
            $r->user()->requirePermission('customers.view');
            $customer = Customer::withTrashed()->findOrFail($id);
            abort_unless($r->user()->canAccessCustomer($customer), 403);
            $path = $customer->photo_path;
        } elseif ($type === 'reading') {
            $r->user()->requirePermission('readings.view');
            $reading = Reading::with('customer')->findOrFail($id);
            abort_unless($r->user()->canAccessCustomer($reading->customer), 403);
            $path = $reading->photo_path;
        } else {
            abort(404);
        }
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }
}
