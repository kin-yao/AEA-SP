<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Equipment;
use App\Models\User;
use App\Support\ServiceSchedule;
use App\Support\Settings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ContractScheduleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_quarterly_dates_are_made_between_start_and_end(): void
    {
        $d = ServiceSchedule::generate(now()->parse('2026-01-15'), now()->parse('2026-12-31'), 3, 'm');
        $this->assertSame(['2026-04-15', '2026-07-15', '2026-10-15'], $d);
        $this->assertSame(['2026-04-15', '2026-07-15', '2026-10-15', '2027-01-13'], ServiceSchedule::generate(now()->parse('2026-01-15'), now()->parse('2027-01-13'), 3, 'm'));
        $this->assertSame([], ServiceSchedule::generate(now()->parse('2026-02-01'), now()->parse('2026-01-01'), 3, 'm'));
    }

    public function test_settings_expose_types_and_frequencies(): void
    {
        $this->assertContains('Full service', Settings::contractTypes());
        $this->assertSame(['every' => 3, 'unit' => 'm'], Settings::maintenanceFrequencies()['Quarterly']);
    }

    public function test_create_needs_a_machine_and_saves_editable_dates(): void
    {
        Storage::fake('local');
        $this->actingAs(User::role('Service Admin')->firstOrFail());
        $m = Equipment::firstOrFail();

        $c = Livewire::test('contracts.create')
            ->set('customer_id', (string) $m->customer_id)
            ->set('frequency', 'Quarterly')
            ->set('starts_at', '2026-11-01')
            ->set('ends_at', '2027-11-01')
            ->set('scan', UploadedFile::fake()->create('c.pdf', 50, 'application/pdf'));

        $this->assertCount(4, $c->get('dates'));

        $c->call('submit')->assertHasErrors(['machineIds']);

        $dates = $c->get('dates');
        $dates[0] = '2027-02-10';
        $c->set('dates', $dates)->set('machineIds', [(string) $m->id])->call('submit')->assertHasNoErrors();

        $contract = Contract::latest('id')->first();
        $this->assertSame('Quarterly', $contract->frequency);
        $this->assertSame([$m->id], $contract->equipment()->pluck('equipment.id')->all());
        $this->assertSame(4, $contract->serviceDates()->count());
        $this->assertSame('2027-02-10', $contract->serviceDates()->orderBy('due_on')->first()->due_on->toDateString());
    }

    public function test_show_page_loads_and_dates_can_be_edited(): void
    {
        $this->actingAs(User::role('Service Admin')->firstOrFail());
        $contract = Contract::firstOrFail();
        $contract->serviceDates()->create(['due_on' => $contract->starts_at->copy()->addDays(5)->toDateString()]);

        $this->get('/contracts/'.$contract->id)->assertOk()->assertSee('Planned service dates');

        $c = Livewire::test('contracts.show', ['contract' => $contract])->call('startEditing');
        $edits = $c->get('dateEdits');
        $this->assertNotEmpty($edits);
        $id = array_key_first($edits);
        $new = $contract->starts_at->copy()->addDays(9)->toDateString();
        $c->set("dateEdits.$id", $new)->call('saveDates')->assertHasNoErrors();
        $this->assertSame($new, $contract->serviceDates()->find($id)->due_on->toDateString());

        $c->call('startEditing')->set("dateEdits.$id", '2001-01-01')->call('saveDates')->assertHasErrors();
    }
}
