<?php

namespace App\Providers;

use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\WorkOrder;
use App\Models\Contract;
use App\Policies\QuotationPolicy;
use App\Policies\ServiceRequestPolicy;
use App\Policies\WorkOrderPolicy;
use App\Policies\ContractPolicy;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(ServiceRequest::class, ServiceRequestPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);
    }
}