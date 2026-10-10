<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TechnicianEquipmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_technician_sees_every_machine_and_its_page(): void
    {
        $tech = User::role('Technician')->firstOrFail();
        $this->actingAs($tech);

        $foreign = Equipment::firstOrFail()->replicate();
        $foreign->serial_number = 'ZZ-'.uniqid();
        $foreign->save();

        Livewire::test('my-equipment')->set('search', $foreign->serial_number)->assertSee($foreign->serial_number);
        $this->get('/my-equipment/'.$foreign->id)->assertOk()->assertSee('Contracts covering this machine')->assertSee('Earlier service reports');

        $mine = Livewire::test('my-equipment')->set('scope', 'mine')->viewData('machineTotal');
        $all = Livewire::test('my-equipment')->viewData('machineTotal');
        $this->assertGreaterThanOrEqual($mine, $all);
    }

    public function test_status_filter_matches_the_machine_status(): void
    {
        $this->actingAs(User::role('Technician')->firstOrFail());
        foreach (['Overdue', 'Due soon', 'Active'] as $st) {
            $rows = Livewire::test('my-equipment')->set('statusFilter', $st)->viewData('machines');
            foreach ($rows as $r) {
                $this->assertSame($st, $r['status']);
            }
        }
    }

    public function test_others_still_cannot_use_the_technician_pages(): void
    {
        $this->actingAs(User::role('Finance')->firstOrFail());
        $this->get('/my-equipment')->assertForbidden();
    }
}
