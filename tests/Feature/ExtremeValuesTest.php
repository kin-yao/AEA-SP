<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/** Largest values every form allows must still save, and the totals must not overflow. */
class ExtremeValuesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_largest_invoice_is_refused_cleanly_or_saved_correctly(): void
    {
        $this->actingAs(User::where('email', 'w.achieng@aealimited.com')->first());
        $job = WorkOrder::whereHas('documents', fn ($q) => $q->where('type', 'rep')->where('status', 'Released'))->firstOrFail();
        $ids = $job->invoices()->pluck('id');
        \DB::table('payments')->whereIn('invoice_id', $ids)->delete();
        \DB::table('invoice_items')->whereIn('invoice_id', $ids)->delete();
        $job->invoices()->delete();

        $t = Livewire::test('invoices.create', ['job' => $job])->set('items', array_fill(0, 100, ['description' => 'Max', 'quantity' => '1000000', 'rate' => '999999999.99']))->set('vatRate', '100');
        $t->call('submit');
        $inv = Invoice::where('work_order_id', $job->id)->first();
        if ($inv) {
            $this->assertGreaterThan(0, $inv->amount_minor, 'amount overflowed to a negative or zero value');
        } else {
            $t->assertHasErrors();
        }
    }

    public function test_largest_quotation_is_refused_cleanly_or_saved_correctly(): void
    {
        $this->actingAs(User::where('email', 'h.murage@aealimited.com')->first());
        $t = Livewire::test('quotations.create')->set('customerId', (string) Customer::value('id'))->set('scope', 'Everything')->set('labour', '999999999.99')->set('validityDays', '365')
            ->set('items', array_fill(0, 100, ['description' => 'Max', 'quantity' => 1000000, 'rate' => '999999999.99']));
        $t->call('submit');
        $q = \App\Models\Quotation::where('scope', 'Everything')->latest('id')->first();
        if ($q) {
            $this->assertGreaterThan(0, $q->total_minor ?? $q->subtotalMinor(), 'quotation total overflowed');
        } else {
            $t->assertHasErrors();
        }
    }

    public function test_two_people_paying_at_once_cannot_overpay(): void
    {
        $inv = Invoice::where('status', 'Unpaid')->firstOrFail();
        $a = Invoice::find($inv->id);
        $b = Invoice::find($inv->id); // a second screen that loaded before the first payment
        \App\Models\Payment::recordAgainst($a, 'RCP-T'.mt_rand(100000, 999999), $inv->balanceMinor(), 'Cheque', User::value('id'));
        $this->expectException(\DomainException::class);
        \App\Models\Payment::recordAgainst($b, 'RCP-T'.mt_rand(100000, 999999), $inv->balanceMinor(), 'Cheque', User::value('id'));
    }

    public function test_two_issues_cannot_take_stock_below_zero(): void
    {
        $item = \App\Models\InventoryItem::where('quantity', '>=', 10)->firstOrFail();
        $item->update(['quantity' => 10]);
        $a = \App\Models\InventoryItem::find($item->id);
        $b = \App\Models\InventoryItem::find($item->id);
        \App\Models\StockMovement::recordAgainst($a, 'MOV-T'.mt_rand(100000, 999999), 'Issue', -8, User::value('id'));
        try {
            \App\Models\StockMovement::recordAgainst($b, 'MOV-T'.mt_rand(100000, 999999), 'Issue', -8, User::value('id'));
            $this->fail('the second issue should have been refused');
        } catch (\DomainException) {
            $this->assertSame(2, (int) \App\Models\InventoryItem::find($item->id)->quantity);
        }
    }
}
