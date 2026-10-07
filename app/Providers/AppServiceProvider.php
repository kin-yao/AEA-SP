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
use App\Models\Branch;
use App\Models\Country;
use App\Models\EquipmentCategory;
use App\Models\Payment;
use App\Observers\AuditObserver;
use App\Services\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        require_once app_path('Support/helpers.php');
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

        // Audit trail: every create, change and delete on these records, plus sign-ins.
        foreach ([User::class, Customer::class, Equipment::class, Contract::class, Invoice::class, Payment::class, Quotation::class, InventoryItem::class, WorkOrder::class, ServiceRequest::class, Document::class, Branch::class, Country::class, EquipmentCategory::class] as $model) {
            $model::observe(AuditObserver::class);
        }

        Event::listen(Login::class, function (Login $e) {
            $ok = $e->user->status === 'Active';
            Audit::record($ok ? 'login' : 'login_blocked', $ok ? 'Signed in' : 'Tried to sign in while the account is locked', null, null, $e->user);
        });
        Event::listen(Logout::class, function (Logout $e) {
            if ($e->user && $e->user->status === 'Active') {
                Audit::record('logout', 'Signed out', null, null, $e->user);
            }
        });
        Event::listen(Failed::class, function (Failed $e) {
            $email = strtolower(trim((string) ($e->credentials['email'] ?? '')));
            Audit::record('login_failed', 'Failed sign-in for '.($email ?: 'no email'), null, null, null, $email ?: null);
        });
    }
}
