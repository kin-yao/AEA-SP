<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/** Parts on a submitted service report come off stock by themselves. Run against a seeded database. */
class PartsDeductionTest extends TestCase
{
    use DatabaseTransactions;

    private function submitWith(WorkOrder $job, array $parts)
    {
        $png = 'data:image/png;base64,iVBORw0KGgo=';

        return Livewire::test('jobs.report', ['job' => $job])
            ->set('nature', [setting('nature_of_visit')[0]])
            ->set('faultDescription', 'Fault')->set('cause', 'Cause')->set('correction', 'Fix')->set('finalResult', 'Working')
            ->set('repairerSignature', $png)->set('customerSignoffName', 'Jane, Manager')->set('customerSignature', $png)
            ->set('parts', $parts);
    }

    public function test_submitting_a_report_deducts_stock_once_and_a_resubmit_adjusts_only_the_difference(): void
    {
        $job = WorkOrder::whereNotNull('assigned_technician_id')->whereNotNull('customer_id')
            ->whereDoesntHave('documents', fn ($q) => $q->where('type', 'rep'))->firstOrFail();
        $job->update(['status' => 'On site', 'contract_id' => null]);
        $tech = User::findOrFail($job->assigned_technician_id);
        $this->actingAs($tech);
        $item = InventoryItem::create(['code' => 'T'.uniqid(), 'name' => 'Test load cell', 'category' => 'Parts', 'quantity' => 10, 'reorder_level' => 1, 'branch_id' => $tech->branch_id ?? \App\Models\Branch::firstOrFail()->id]);

        $part = fn ($qty, $ret = 0) => [['inventoryItemId' => (string) $item->id, 'item' => 'Test load cell', 'partNumber' => '', 'quantity' => $qty, 'source' => 'Store', 'returned' => $ret]];

        $c = $this->submitWith($job, $part(3, 1));
        $c->call('submit')->assertHasNoErrors();
        $this->assertSame(8, $item->fresh()->quantity); // 3 used, 1 returned, so 2 taken out
        $this->assertSame(1, StockMovement::where('work_order_id', $job->id)->where('inventory_item_id', $item->id)->count());

        // the same lines again take nothing more out
        \App\Services\PartsIssue::forReport($job, $job->documents()->where('type', 'rep')->first()->reportDetail, $tech->id);
        $this->assertSame(8, $item->fresh()->quantity);

        // the technician corrects it to 4 used: only 2 more come out
        $c->set('parts', $part(4, 0))->call('submit')->assertHasNoErrors();
        $this->assertSame(6, $item->fresh()->quantity);
    }

    public function test_not_enough_stock_does_not_block_the_report(): void
    {
        $job = WorkOrder::whereNotNull('assigned_technician_id')->whereNotNull('customer_id')
            ->whereDoesntHave('documents', fn ($q) => $q->where('type', 'rep'))->firstOrFail();
        $job->update(['status' => 'On site', 'contract_id' => null]);
        $this->actingAs(User::findOrFail($job->assigned_technician_id));
        $item = InventoryItem::create(['code' => 'T'.uniqid(), 'name' => 'Scarce part', 'category' => 'Parts', 'quantity' => 1, 'reorder_level' => 0, 'branch_id' => \App\Models\Branch::firstOrFail()->id]);

        $this->submitWith($job, [['inventoryItemId' => (string) $item->id, 'item' => 'Scarce part', 'partNumber' => '', 'quantity' => 5, 'source' => '', 'returned' => 0]])
            ->call('submit')->assertHasNoErrors();

        $this->assertSame(1, $item->fresh()->quantity);
        $this->assertSame('Awaiting review', $job->fresh()->status);
    }

    public function test_a_technician_cannot_overwrite_someone_elses_report_by_changing_the_document_id(): void
    {
        $job = WorkOrder::whereNotNull('assigned_technician_id')->whereNotNull('customer_id')->firstOrFail();
        $job->update(['status' => 'On site', 'contract_id' => null]);
        $this->actingAs(User::findOrFail($job->assigned_technician_id));

        $other = \App\Models\Document::create([
            'reference' => 'RP-X'.uniqid(), 'type' => 'rep', 'customer_id' => $job->customer_id, 'status' => 'Released',
            'work_order_id' => WorkOrder::where('id', '!=', $job->id)->value('id'), 'filed_by' => User::role('Supervisor')->value('id'),
        ]);

        try {
            $this->submitWith($job, [])->set('documentId', $other->id)->call('saveDraft');
        } catch (\Throwable $e) {
            // refusing outright is fine too
        }

        $this->assertSame('Released', $other->fresh()->status);
    }

    public function test_stock_search_is_small_and_pickable(): void
    {
        $job = WorkOrder::whereNotNull('assigned_technician_id')->whereNotNull('customer_id')->firstOrFail();
        $job->update(['status' => 'On site', 'contract_id' => null]);
        $this->actingAs(User::findOrFail($job->assigned_technician_id));
        $item = InventoryItem::firstOrFail();

        $c = Livewire::test('jobs.report', ['job' => $job]);
        $this->assertLessThanOrEqual(8, $c->instance()->stockMatches(substr($item->name, 0, 2))->count());
        $this->assertCount(0, $c->instance()->stockMatches('a'));
        $c->call('pickStock', 0, $item->id)->assertSet('parts.0.inventoryItemId', (string) $item->id)->assertSet('parts.0.item', $item->name);
        $c->call('clearStock', 0)->assertSet('parts.0.inventoryItemId', '');
    }
}
