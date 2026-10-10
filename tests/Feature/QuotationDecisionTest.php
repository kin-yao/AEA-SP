<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InboxNotification;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/** Reviewing, rejecting with a reason, and escalating quotations. Run against a seeded database. */
class QuotationDecisionTest extends TestCase
{
    use DatabaseTransactions;

    private function waiting(string $level = 'Supervisor'): Quotation
    {
        $creator = User::role('Service Admin')->firstOrFail();
        $q = Quotation::create([
            'reference' => 'QT-D'.uniqid(), 'customer_id' => Customer::firstOrFail()->id, 'scope' => 'Decision test',
            'labour_minor' => 1000, 'validity_days' => 30, 'currency_code' => 'KES', 'vat_rate' => 0.16,
            'created_by' => $creator->id, 'approval_threshold' => $level, 'status' => 'Awaiting '.$level,
        ]);
        $q->items()->create(['description' => 'Part', 'quantity' => 1, 'rate_minor' => 5000]);

        return $q;
    }

    public function test_rejecting_needs_a_reason_which_is_saved_shown_and_sent_to_the_author(): void
    {
        $q = $this->waiting();
        $this->actingAs(User::role('Supervisor')->firstOrFail());

        $c = Livewire::test('quotations.decision', ['quotation' => $q])->call('choose', 'reject');
        $c->set('reason', '')->call('reject')->assertHasErrors(['reason']);
        $this->assertSame('Awaiting Supervisor', $q->fresh()->status);

        $c->set('reason', 'Labour is too high for a one day job')->call('reject')->assertHasNoErrors()->assertDispatched('quotation-decided');
        $q = $q->fresh();
        $this->assertSame('Rejected', $q->status);
        $this->assertSame('Labour is too high for a one day job', $q->rejection_reason);
        $this->assertNotNull($q->decided_by);

        $n = InboxNotification::where('user_id', $q->created_by)->where('title', 'Quotation rejected')->latest('id')->first();
        $this->assertStringContainsString('Labour is too high', implode(' ', $n->lines));

        // staff see the reason on the quotation page, customers do not
        $this->get('/quotations/'.$q->id)->assertSee('Labour is too high for a one day job');
        $portal = User::where('customer_id', $q->customer_id)->first();
        if ($portal) {
            $this->actingAs($portal)->get('/quotations/'.$q->id)->assertDontSee('Labour is too high for a one day job');
        }
    }

    public function test_the_wrong_person_cannot_decide(): void
    {
        $q = $this->waiting('Manager');
        $this->actingAs(User::role('Supervisor')->firstOrFail());
        Livewire::test('quotations.decision', ['quotation' => $q])->call('approve')->assertForbidden();
        $this->assertSame('Awaiting Manager', $q->fresh()->status);
    }

    public function test_a_supervisor_can_escalate_and_the_manager_is_told(): void
    {
        $q = $this->waiting();
        $this->actingAs(User::role('Supervisor')->firstOrFail());
        Livewire::test('quotations.decision', ['quotation' => $q])->call('choose', 'escalate')->set('note', 'Unusual discount')->call('escalate')->assertHasNoErrors();

        $q = $q->fresh();
        $this->assertSame('Manager', $q->approval_threshold);
        $this->assertSame('Awaiting Manager', $q->status);
        $this->assertSame('Unusual discount', $q->escalation_note);
        $mgr = User::role('Manager')->firstOrFail();
        $this->assertSame(1, InboxNotification::where('user_id', $mgr->id)->where('title', 'Quotation escalated for your decision')->count());

        // it is out of the Supervisor's hands now, and a Manager cannot "escalate"
        $this->assertFalse(User::role('Supervisor')->first()->can('approve', $q));
        $this->assertFalse($mgr->can('escalate', $q));
    }

    public function test_the_approvals_page_opens_a_request_for_review_then_approves(): void
    {
        $q = $this->waiting();
        $this->actingAs(User::role('Supervisor')->firstOrFail());
        Livewire::test('approvals')->assertSee($q->reference)->call('review', $q->id)->assertSet('reviewId', $q->id)
            ->assertSeeLivewire('quotations.decision')->assertSee('Decision test');
        Livewire::test('quotations.decision', ['quotation' => $q, 'details' => true])->call('approve');
        $this->assertSame('Approved', $q->fresh()->status);
    }

    public function test_a_rejected_quotation_can_be_copied_into_a_corrected_one(): void
    {
        $q = $this->waiting();
        $q->reject('Fix the rate', User::role('Supervisor')->firstOrFail()->id);
        $this->actingAs(User::role('Service Admin')->firstOrFail());
        Livewire::withQueryParams(['revise' => $q->id])->test('quotations.create')
            ->assertSet('scope', 'Decision test')->assertSet('items.0.description', 'Part')->assertSet('revising', $q->reference);
    }
}
