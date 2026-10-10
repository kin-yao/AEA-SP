<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\FinancePdf;
use App\Services\InvoiceBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceFlowTest extends TestCase
{
    use DatabaseTransactions;

    private function invoice(string $status = 'Unpaid'): Invoice
    {
        $c = Customer::firstOrFail();
        $i = Invoice::create([
            'reference' => 'INV-T'.uniqid(), 'customer_id' => $c->id, 'issued_at' => now(), 'due_at' => now()->addDays(30),
            'amount_minor' => 100000, 'vat_rate' => 0.16, 'currency_code' => 'KES', 'status' => $status, 'raised_by' => User::role('Finance')->firstOrFail()->id,
        ]);
        $i->items()->create(['description' => 'Service', 'quantity' => 1, 'rate_minor' => 86207]);

        return $i;
    }

    public function test_payment_makes_a_receipt_that_downloads_and_shares(): void
    {
        $inv = $this->invoice();
        $this->actingAs(User::role('Finance')->firstOrFail());

        $c = Livewire::test('invoices.show', ['invoice' => $inv]);
        $this->assertSame('1000.00', $c->get('paymentAmount'));   // full balance is pre-filled
        $c->set('paymentAmount', '400')->call('recordPayment')->assertHasNoErrors()->assertSee('Download receipt');

        $p = Payment::where('invoice_id', $inv->id)->firstOrFail();
        $this->assertSame('Part paid', $inv->fresh()->status);
        $c->call('downloadReceipt', $p->id)->assertFileDownloaded($p->reference.'.pdf');
        $c->call('downloadPdf')->assertFileDownloaded($inv->reference.'.pdf');

        $c->call('makeLink', $p->id);
        $link = $c->get('shareLink');
        auth()->logout();
        $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(preg_replace('/signature=\w+/', 'signature=bad', $link))->assertForbidden();
        $this->assertStringStartsWith('%PDF', FinancePdf::receipt($p));
    }

    public function test_drafts_cannot_be_shared_and_customers_cannot_share(): void
    {
        $draft = $this->invoice('Draft');
        $this->actingAs(User::role('Finance')->firstOrFail());
        Livewire::test('invoices.show', ['invoice' => $draft])->call('makeLink')->assertStatus(403);
    }

    public function test_customer_portal_user_cannot_make_links(): void
    {
        $inv = $this->invoice();
        $portal = User::where('customer_id', $inv->customer_id)->first();
        if (! $portal) {
            $this->markTestSkipped('no portal user');
        }
        $this->actingAs($portal);
        Livewire::test('invoices.show', ['invoice' => $inv])->call('makeLink')->assertForbidden();
    }

    public function test_one_click_invoice_from_a_quotation(): void
    {
        $this->actingAs(User::role('Finance')->firstOrFail());
        $q = \App\Models\Quotation::create([
            'reference' => 'QT-F'.uniqid(), 'customer_id' => Customer::firstOrFail()->id, 'scope' => 'Finance test',
            'labour_minor' => 20000, 'validity_days' => 30, 'currency_code' => 'KES', 'vat_rate' => 0.16,
            'created_by' => User::role('Service Admin')->firstOrFail()->id, 'status' => 'Approved',
        ]);
        $q->items()->create(['description' => 'Part', 'quantity' => 2, 'rate_minor' => 50000]);
        $job = $q->convertToJob(User::role('Technician')->firstOrFail()->id, today()->addWeek()->toDateString(), 'WO-F'.uniqid());
        \App\Models\Document::create(['reference' => 'RP-F'.uniqid(), 'type' => 'rep', 'work_order_id' => $job->id, 'customer_id' => $job->customer_id, 'status' => 'Released', 'filed_by' => User::role('Service Admin')->firstOrFail()->id]);
        $this->assertTrue(InvoiceBuilder::canRaiseDirectly($job->fresh()));

        Livewire::test('invoices')->call('quickInvoice', $job->id)->assertHasNoErrors();
        $inv = Invoice::where('work_order_id', $job->id)->firstOrFail();
        $this->assertSame('Unpaid', $inv->status);
        $this->assertSame(139200, (int) $inv->amount_minor);   // (2 x 500.00 + 200.00) x 1.16
        $this->assertSame($inv->amount_minor, (int) ($inv->itemsSubtotalMinor() + $inv->vatMinor()));

        // a second click does not make a second invoice
        Livewire::test('invoices')->call('quickInvoice', $job->id)->assertHasErrors(['quick']);
        $this->assertSame(1, Invoice::where('work_order_id', $job->id)->count());
    }

    public function test_receipts_page_downloads(): void
    {
        $inv = $this->invoice();
        $f = User::role('Finance')->firstOrFail();
        $p = Payment::recordAgainst($inv, 'RCP-T'.uniqid(), 5000, 'Cash', $f->id);
        $this->actingAs($f);
        Livewire::test('receipts')->call('download', $p->id)->assertFileDownloaded($p->reference.'.pdf');
    }
}
