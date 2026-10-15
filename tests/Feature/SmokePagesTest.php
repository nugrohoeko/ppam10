<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmokePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_real_admin_screens_render_with_demo_transactions(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::where('email', 'admin@pam10.test')->first() ?? User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->firstOrFail();
        $this->actingAs($admin)->withoutVite();
        foreach (['/', '/customers', '/customers/create', '/customers/1', '/customers/1/edit', '/regions', '/meters', '/readings', '/readings?customer=PAM10-000001', '/readings?verify=1', '/tariffs', '/simulator?scheme_id=1&usage=21', '/invoices', '/invoices?arrears=1', '/invoices/print?ids[]=1', '/payments', '/finance', '/periods', '/reports', '/barcodes', '/imports', '/users', '/roles', '/settings', '/audit'] as $url) {
            $this->get($url)->assertOk();
        }
        $pdf=$this->get('/invoices/print?ids[]=1&pdf=1')->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_guests_are_redirected_from_protected_pages(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/payments')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }
}
