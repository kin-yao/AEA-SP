<?php

namespace App\Providers;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\Invoice;
use App\Models\InventoryItem;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Policies\ContractPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EquipmentPolicy;
use App\Policies\InventoryItemPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\QuotationPolicy;
use App\Policies\ServiceRequestPolicy;
use App\Policies\UserPolicy;
use App\Policies\WorkOrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(ServiceRequest::class, ServiceRequestPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(InventoryItem::class, InventoryItemPolicy::class);
        Gate::policy(Equipment::class, EquipmentPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }
}
