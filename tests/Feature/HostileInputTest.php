<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Throws odd and hostile values at every important form. A form may reject the input
 * (validation) but must never crash. Run against a seeded database, changes roll back:
 *
 *   DB_DATABASE=database/database.sqlite php artisan test --filter=HostileInputTest
 */
class HostileInputTest extends TestCase
{
    use DatabaseTransactions;

    /** Values a real browser could send for a field of this kind. Tampered types (an array for a text box) are not tested. */
    private function values(mixed $like): array
    {
        if (is_int($like)) {
            return ['', '0', '-1', '999999999', '1.5', 0, -5, 99];
        }

        return [
            '', ' ', 'abc', '0', '-1', '-0.01', '1e999', '99999999999999999999', 'NaN', '0.001', '12.345', '2026-02-30', '0000-00-00',
            "<script>alert(1)</script>", "'; DROP TABLE users; --", "\0", "😀😀😀", "a\nb\r\nc", '../../etc/passwd',
            str_repeat('A', 70000), '１２３', '٣٤٥', '+254 712 345 678', '07I2 345 678',
        ];
    }

    private function itemValues(): array
    {
        $out = [];
        foreach (['description', 'quantity', 'rate'] as $col) {
            foreach ($this->values('') as $v) {
                $out[] = [['description' => 'Part', 'quantity' => '1', 'rate' => '100', $col => $v]];
            }
        }
        $out[] = [];
        $out[] = array_fill(0, 150, ['description' => 'Part', 'quantity' => '1', 'rate' => '100']);

        return $out;
    }

    private function as(string $email): void
    {
        $this->actingAs(User::where('email', $email)->firstOrFail());
    }

    private function fuzz(string $component, string $method, array $valid, array $params = [], ?\Closure $reset = null): array
    {
        $bad = [];
        try {
            $reset && $reset();
            $t = Livewire::test($component, $params);
            foreach ($valid as $k => $v) {
                $t->set($k, $v);
            }
            $t->call($method)->assertHasNoErrors();
        } catch (\Throwable $e) {
            return [$component.' baseline' => class_basename($e).': '.substr($e->getMessage(), 0, 300)];
        }

        foreach ($valid as $field => $_) {
            foreach ($field === 'items' ? $this->itemValues() : $this->values($valid[$field]) as $v) {
                try {
                    $reset && $reset();
                    $t = Livewire::test($component, $params);
                    foreach ($valid as $k => $x) {
                        $t->set($k, $k === $field ? $v : $x);
                    }
                    $t->call($method);
                } catch (ValidationException) {
                    // fine, rejected
                } catch (\Throwable $e) {
                    $label = is_scalar($v) || $v === null ? substr(var_export($v, true), 0, 24) : gettype($v);
                    $bad[$component.'::'.$field.' = '.$label] = class_basename($e).': '.substr($e->getMessage(), 0, 110);
                }
            }
        }

        return $bad;
    }

    private function report(array $bad): void
    {
        foreach (array_slice($bad, 0, 25, true) as $k => $m) {
            fwrite(STDERR, "CRASH $k -> $m\n");
        }
        $this->assertSame([], $bad, count($bad).' inputs crashed a form');
    }

    public function test_customer_forms_never_crash(): void
    {
        $this->as('h.murage@aealimited.com');
        $this->report($this->fuzz('customers.create', 'save', [
            'name' => 'Acme Ltd', 'branch_id' => Branch::value('id'), 'kra_pin' => 'P051234567Z', 'po_box' => 'P.O. Box 1',
            'main_contact_name' => 'Jane Doe', 'main_contact_email' => 'jane'.uniqid().'@acme.co.ke', 'main_contact_phone' => '0712345678',
        ]));
    }

    public function test_equipment_form_never_crashes(): void
    {
        $this->as('h.murage@aealimited.com');
        $this->report($this->fuzz('equipment.create', 'save', [
            'serial_number' => 'ZZ-'.uniqid(), 'model' => 'Scale', 'customer_id' => Customer::value('id'), 'category' => 'Platform scale',
            'cover' => 'Chargeable', 'installed_at' => '2025-01-01', 'warranty_expires_at' => '2027-01-01', 'next_visit_due_at' => '2026-12-01',
        ]));
    }

    public function test_inventory_form_never_crashes(): void
    {
        $this->as('h.murage@aealimited.com');
        $this->report($this->fuzz('inventory.create', 'save', [
            'code' => 'ZZ'.mt_rand(100, 999999), 'name' => 'Load cell', 'category' => 'Spare part', 'unit' => 'Piece', 'branch_id' => Branch::value('id'),
            'quantity' => 5, 'reorder_level' => 2, 'cost' => '100.50', 'price' => '150',
        ]));
    }

    public function test_quotation_form_never_crashes(): void
    {
        $this->as('h.murage@aealimited.com');
        $this->report($this->fuzz('quotations.create', 'submit', [
            'customerId' => (string) Customer::value('id'), 'scope' => 'Replace the load cell and recalibrate', 'labour' => '1000', 'validityDays' => '30',
            'items' => [['description' => 'Load cell', 'quantity' => 1, 'rate' => '5000']],
        ]));
    }

    public function test_request_form_never_crashes(): void
    {
        $this->as('h.murage@aealimited.com');
        $this->report($this->fuzz('requests.create', 'submit', [
            'customer_id' => (string) Customer::value('id'), 'fault_description' => 'Display flickers under load', 'cover' => 'Chargeable', 'priority' => 'High',
            'contact_name' => 'Jane Doe', 'equipment_description' => 'Platform scale',
        ]));
    }

    public function test_user_form_never_crashes(): void
    {
        $this->as('i.ict@aealimited.com');
        $this->report($this->fuzz('users.create', 'save', [
            'name' => 'Test Person', 'email' => 't'.uniqid().'@aealimited.com', 'phone' => '0712345678', 'role' => 'Technician', 'branch_id' => Branch::value('id'),
        ]));
    }

    public function test_invoice_form_never_crashes(): void
    {
        $this->as('w.achieng@aealimited.com');
        $job = WorkOrder::whereHas('documents', fn ($q) => $q->where('type', 'rep')->where('status', 'Released'))->firstOrFail();
        $clear = function () use ($job) {
            $ids = $job->invoices()->pluck('id');
            \DB::table('payments')->whereIn('invoice_id', $ids)->delete();
            \DB::table('invoice_items')->whereIn('invoice_id', $ids)->delete();
            $job->invoices()->delete();
        };
        $clear();
        $this->report($this->fuzz('invoices.create', 'submit', [
            'items' => [['description' => 'Labour', 'quantity' => '1', 'rate' => '1000']], 'vatRate' => '16', 'dueAt' => now()->addDays(30)->toDateString(),
        ], ['job' => $job], $clear));
    }

    public function test_stored_markup_is_escaped_on_screen(): void
    {
        $this->as('h.murage@aealimited.com');
        $c = Customer::create(['reference' => 'CUS-X'.mt_rand(1000, 9999), 'name' => '<img src=x onerror=alert(1)> Ltd', 'branch_id' => Branch::value('id')]);
        foreach (['customers', 'jobs', 'quotations'] as $page) {
            $html = Livewire::test($page)->html();
            $this->assertStringNotContainsString('<img src=x onerror', $html, "$page printed stored markup unescaped");
        }
        $html = Livewire::test('customers.show', ['customer' => $c])->html();
        $this->assertStringNotContainsString('<img src=x onerror', $html);
    }
}
