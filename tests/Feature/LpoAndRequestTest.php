<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/** New customer and machine on a request, quotation currency, LPO as the binding document. Run against a seeded database. */
class LpoAndRequestTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::role('Service Admin')->firstOrFail();
    }

    private function quotationWithLpo(string $currency = 'KES'): array
    {
        $customer = Customer::firstOrFail();
        $q = Quotation::create([
            'reference' => 'QT-T'.uniqid(), 'customer_id' => $customer->id, 'scope' => 'Test scope',
            'labour_minor' => 100000, 'validity_days' => 30, 'currency_code' => $currency, 'vat_rate' => 0.16,
            'created_by' => $this->admin()->id, 'status' => 'Approved',
        ]);
        $q->items()->create(['description' => 'Load cell', 'quantity' => 2, 'rate_minor' => 500000]);
        $doc = $q->logLpo('LPO-T'.uniqid(), 'Email', $this->admin()->id);

        return [$q->fresh(), $doc];
    }

    public function test_new_customer_needs_every_field_and_creates_customer_and_site(): void
    {
        $this->actingAs($this->admin());
        $c = Livewire::test('requests.create')->call('toggleNewCustomer')
            ->set('newCustomerName', 'Zed Test Ltd')->call('createCustomer')
            ->assertHasErrors(['newCustomerBranchId', 'newCustomerKraPin', 'newCustomerPoBox', 'newCustomerContactName', 'newCustomerContactEmail', 'newCustomerContactPhone', 'newCustomerSiteAddress']);

        $email = 'zed'.uniqid().'@example.com';
        $c->set('newCustomerBranchId', (string) \App\Models\Branch::firstOrFail()->id)
            ->set('newCustomerKraPin', 'P051234567Z')->set('newCustomerPoBox', 'P.O. Box 12')
            ->set('newCustomerContactName', 'Jane Wanjiru')->set('newCustomerContactEmail', $email)
            ->set('newCustomerContactPhone', '+254712345678')
            ->set('newCustomerSiteName', 'Head office')->set('newCustomerSiteAddress', 'Mombasa Road')
            ->set('ncLat', '-1.3')->set('ncLng', '36.8')
            ->call('createCustomer')->assertHasNoErrors();

        $cust = Customer::where('main_contact_email', $email)->firstOrFail();
        $this->assertSame('P051234567Z', $cust->kra_pin);
        $this->assertSame(1, CustomerSite::where('customer_id', $cust->id)->where('address', 'Mombasa Road')->count());
    }

    public function test_request_can_add_a_new_machine_and_rejects_duplicates_and_foreign_machines(): void
    {
        $this->actingAs($this->admin());
        $mine = Equipment::whereNotNull('customer_id')->firstOrFail();
        $other = Equipment::where('customer_id', '!=', $mine->customer_id)->first();
        $serial = 'SN'.uniqid();

        $base = fn () => Livewire::test('requests.create')->set('customer_id', (string) $mine->customer_id)
            ->set('fault_description', 'Display shows error on start')->set('cover', 'Chargeable')->set('priority', 'Medium');

        $base()->set('equipment_id', '__new')->set('newEquipModel', 'WB-9')->set('newEquipSerial', $mine->serial_number)
            ->call('submit')->assertHasErrors(['newEquipSerial']);

        $base()->set('equipment_id', '__new')->set('newEquipModel', 'WB-9')->set('newEquipSerial', $serial)
            ->call('submit')->assertHasNoErrors();
        $machine = Equipment::where('serial_number', $serial)->firstOrFail();
        $this->assertSame($mine->customer_id, $machine->customer_id);
        $this->assertSame(1, ServiceRequest::where('equipment_id', $machine->id)->count());

        if ($other) {
            $base()->set('equipment_id', (string) $other->id)->call('submit')->assertHasErrors();
        }
    }

    public function test_quotation_currency_is_chosen_and_stored(): void
    {
        $this->actingAs($this->admin());
        $customer = Customer::firstOrFail();
        $c = Livewire::test('quotations.create')->set('customerId', (string) $customer->id);
        $this->assertSame($customer->currencyCode(), $c->get('currencyCode'));

        $code = collect(\App\Models\Currency::codes())->first(fn ($x) => $x !== $customer->currencyCode());
        $c->set('currency', $code)->set('scope', 'Currency test scope')
            ->set('items.0.description', 'Part')->set('items.0.quantity', 1)->set('items.0.rate', '100')
            ->call('submit')->assertHasNoErrors();
        $this->assertSame($code, Quotation::where('scope', 'Currency test scope')->latest('id')->firstOrFail()->currency_code);

        Livewire::test('quotations.create')->set('customerId', (string) $customer->id)->set('currency', 'ZZZ')
            ->set('scope', 'Bad currency')->call('submit')->assertHasErrors(['currency']);
    }

    public function test_lpo_starts_as_a_copy_and_can_be_edited_with_a_reason_until_invoiced(): void
    {
        [$q, $doc] = $this->quotationWithLpo();
        $lpo = $doc->lpoDetail->fresh();
        $this->assertSame(1, $lpo->items()->count());
        $this->assertSame(100000, (int) $lpo->labour_minor);
        $this->assertSame($q->totalMinor(), $lpo->totalMinor());
        $this->assertSame($q->totalMinor(), $q->bindingTotalMinor());

        $this->actingAs($this->admin());
        $c = Livewire::test('documents.show', ['document' => $doc])->call('startEditLpo')
            ->set('lpoItems.0.rate', '4000')->set('lpoNote', '')->call('saveLpo')->assertHasErrors(['lpoNote']);
        $c->set('lpoNote', 'Negotiated 20 percent off')->call('saveLpo')->assertHasNoErrors();

        $lpo = $lpo->fresh();
        $this->assertSame(400000, (int) $lpo->items()->first()->rate_minor);
        $this->assertSame('Negotiated 20 percent off', $lpo->change_note);
        $this->assertLessThan($q->totalMinor(), $q->fresh()->bindingTotalMinor());

        // the original quotation is untouched
        $this->assertSame(500000, (int) $q->items()->first()->rate_minor);

        // a technician cannot edit
        $tech = User::role('Technician')->firstOrFail();
        $this->assertFalse($tech->can('editLpo', $doc));
        $this->assertTrue($this->admin()->can('editLpo', $doc));
    }

    public function test_invoice_is_raised_from_the_lpo_and_locks_it(): void
    {
        [$q, $doc] = $this->quotationWithLpo();
        $admin = $this->admin();
        $this->actingAs($admin);
        Livewire::test('documents.show', ['document' => $doc])->call('startEditLpo')
            ->set('lpoItems.0.rate', '4000')->set('lpoNote', 'Agreed lower price')->call('saveLpo')->assertHasNoErrors();

        $job = $q->convertToJob(User::role('Technician')->firstOrFail()->id, today()->addWeek()->toDateString(), 'WO-T'.uniqid());
        $this->assertSame($q->fresh()->bindingTotalMinor(), (int) $job->value_minor);
        Document::create(['reference' => 'RP-T'.uniqid(), 'type' => 'rep', 'work_order_id' => $job->id, 'customer_id' => $job->customer_id, 'status' => 'Released', 'filed_by' => $admin->id]);

        $finance = User::role('Finance')->firstOrFail();
        $this->actingAs($finance);
        $c = Livewire::test('invoices.create', ['job' => $job])->assertSet('lpoDocumentId', $doc->id);
        $this->assertSame(4000.0, (float) $c->get('items.0.rate'));

        // tampering with the lines is ignored: the LPO prices win
        $c->set('items.0.rate', '1')->call('submit')->assertHasNoErrors();
        $inv = Invoice::where('work_order_id', $job->id)->firstOrFail();
        $this->assertSame($doc->id, (int) $inv->lpo_document_id);
        $this->assertSame($q->fresh()->lpoDetail->totalMinor(), (int) $inv->amount_minor);

        // now locked
        $this->assertFalse($admin->can('editLpo', $doc->fresh()));
    }

    public function test_a_site_from_another_customer_is_refused(): void
    {
        $this->actingAs($this->admin());
        $site = CustomerSite::firstOrFail();
        $otherCustomer = Customer::where('id', '!=', $site->customer_id)->firstOrFail();

        Livewire::test('quotations.create')->set('customerId', (string) $otherCustomer->id)->set('siteId', (string) $site->id)
            ->set('scope', 'Wrong site scope')->set('items.0.description', 'Part')->set('items.0.rate', '10')
            ->call('submit')->assertHasErrors(['siteId']);

        Livewire::test('requests.create')->set('customer_id', (string) $otherCustomer->id)->set('customer_site_id', (string) $site->id)
            ->set('fault_description', 'Display shows error on start')->call('submit')->assertHasErrors(['customer_site_id']);
    }
}
