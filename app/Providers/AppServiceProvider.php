<?php

namespace App\Providers;

use App\Models\CashEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reading;
use App\Policies\CustomerPolicy;
use App\Policies\DomainPolicy;
use App\Services\SettingService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::directive('rupiah', fn ($e) => "<?php echo 'Rp '.number_format((int)($e),0,',','.'); ?>");
        Blade::directive('volume', fn ($e) => "<?php echo number_format((float)($e),3,',','.'); ?>");
        Blade::if('allowed', fn ($p) => auth()->user()?->hasPermission($p) ?? false);
        View::composer('layouts.app', function ($view) {
            $view->with('pamName', SettingService::get('pam_name', 'PAM RW.10'));
        });
        foreach ([Customer::class, Reading::class, Invoice::class, Payment::class, CashEntry::class] as $model) {
            Gate::policy($model, DomainPolicy::class);
        }
        Gate::policy(Customer::class, CustomerPolicy::class);
        Paginator::useBootstrapFive();
    }
}
