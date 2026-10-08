<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Attaching certificates, vouchers and delivery notes to a job. Run against a seeded database. */
class JobAttachmentsTest extends TestCase
{
    private function job(): WorkOrder
    {
        return WorkOrder::whereNotNull('assigned_technician_id')->whereNotNull('customer_id')->firstOrFail();
    }

    private function pdf(string $name = 'scan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    public function test_the_job_technician_can_attach_all_three_and_the_customer_sees_them(): void
    {
        Storage::fake('local');
        $job = $this->job();
        $tech = User::findOrFail($job->assigned_technician_id);
        $this->actingAs($tech);
        $n = 'T'.uniqid();

        $c = Livewire::test('jobs.attachments', ['job' => $job]);
        $c->call('openForm', Document::TYPE_CERTIFICATE, Settings::certificateTypes()[1])
            ->set('number', $n.'C')->set('file', $this->pdf())
            ->set('issuedAt', today()->subDay()->toDateString())->set('expiresAt', today()->addYear()->toDateString())
            ->call('save')->assertHasNoErrors();
        $c->call('openForm', Document::TYPE_VOUCHER)->set('number', $n.'V')->set('file', $this->pdf())->call('save')->assertHasNoErrors();
        $c->call('openForm', Document::TYPE_DELIVERY_NOTE)->set('number', $n.'D')->set('file', $this->pdf())->call('save')->assertHasNoErrors();
        $c->call('openForm', Document::TYPE_SCAN)->set('file', $this->pdf())->call('save')->assertHasNoErrors();
        $c->call('openForm', Document::TYPE_OTHER)->set('title', $n.' access letter')->set('file', $this->pdf())->call('save')->assertHasNoErrors();
        $this->assertSame(1, Document::where('work_order_id', $job->id)->where('type', Document::TYPE_OTHER)->where('title', $n.' access letter')->count());
        $this->assertGreaterThanOrEqual(1, Document::where('work_order_id', $job->id)->where('type', Document::TYPE_SCAN)->whereNotNull('file_path')->count());

        $this->assertSame(3, Document::where('work_order_id', $job->id)->where('reference', 'like', $n.'%')->whereNotNull('file_path')->count());
        $cert = Document::where('reference', $n.'C')->firstOrFail();
        $this->assertSame(Settings::certificateTypes()[1], $cert->certificateDetail->certificate_type);

        $customer = User::where('customer_id', $job->customer_id)->first();
        if ($customer) {
            $this->actingAs($customer);
            Livewire::test('jobs.attachments', ['job' => $job])->assertSee($n.'C')->assertSee($n.'V');
        }
    }

    public function test_bad_input_is_refused_with_a_clear_message(): void
    {
        Storage::fake('local');
        $job = $this->job();
        $this->actingAs(User::findOrFail($job->assigned_technician_id));
        $c = Livewire::test('jobs.attachments', ['job' => $job]);

        $c->call('openForm', Document::TYPE_CERTIFICATE, Settings::certificateTypes()[0])->call('save')->assertHasErrors(['file', 'number', 'expiresAt']);
        $c->set('number', 'X1')->set('file', UploadedFile::fake()->create('virus.exe', 10))->set('expiresAt', today()->addYear()->toDateString())->call('save')->assertHasErrors(['file']);
        $c->set('file', $this->pdf())->set('expiresAt', today()->subYear()->toDateString())->call('save')->assertHasErrors(['expiresAt']);
        $c->set('expiresAt', today()->addYear()->toDateString())->set('issuedAt', today()->addDays(3)->toDateString())->call('save')->assertHasErrors(['issuedAt']);
        $c->set('issuedAt', today()->toDateString())->set('file', UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'))->call('save')->assertHasErrors(['file']);
        $c->call('openForm', Document::TYPE_OTHER)->set('file', $this->pdf())->call('save')->assertHasErrors(['title']);
        $c->call('openForm', Document::TYPE_CERTIFICATE, 'Made up type')->assertNotFound();
    }

    public function test_the_wrong_people_cannot_attach(): void
    {
        $job = $this->job();
        $other = User::where('id', '!=', $job->assigned_technician_id)->get()->first(fn ($u) => $u->hasRole('Technician'));
        foreach ([$other, User::get()->first(fn ($u) => $u->hasRole('Customer')), User::get()->first(fn ($u) => $u->hasRole('Finance'))] as $u) {
            if (! $u) {
                continue;
            }
            $this->actingAs($u);
            Livewire::test('jobs.attachments', ['job' => $job])->call('openForm', Document::TYPE_CERTIFICATE, Settings::certificateTypes()[0])->assertForbidden();
        }
        // A supervisor may attach certificates and other documents, but not vouchers or the signed hard copy.
        $sup = User::get()->first(fn ($u) => $u->hasRole('Supervisor'));
        $this->actingAs($sup);
        Livewire::test('jobs.attachments', ['job' => $job])->call('openForm', Document::TYPE_CERTIFICATE, Settings::certificateTypes()[0])->assertSet('open', Document::TYPE_CERTIFICATE);
        Livewire::test('jobs.attachments', ['job' => $job])->call('openForm', Document::TYPE_OTHER)->assertSet('open', Document::TYPE_OTHER);
        Livewire::test('jobs.attachments', ['job' => $job])->call('openForm', Document::TYPE_VOUCHER)->assertForbidden();
        Livewire::test('jobs.attachments', ['job' => $job])->call('openForm', Document::TYPE_SCAN)->assertForbidden();
    }

    public function test_duplicate_numbers_are_refused(): void
    {
        Storage::fake('local');
        $job = $this->job();
        $this->actingAs(User::findOrFail($job->assigned_technician_id));
        $existing = Document::where('type', Document::TYPE_REPORT)->firstOrFail();
        Livewire::test('jobs.attachments', ['job' => $job])->call('openForm', Document::TYPE_DELIVERY_NOTE)
            ->set('number', $existing->reference)->set('file', $this->pdf())->call('save')->assertHasErrors(['number']);
    }

    public function test_uploader_can_remove_their_file(): void
    {
        Storage::fake('local');
        $job = $this->job();
        $tech = User::findOrFail($job->assigned_technician_id);
        $this->actingAs($tech);
        $n = 'R'.uniqid();
        $c = Livewire::test('jobs.attachments', ['job' => $job])->call('openForm', Document::TYPE_DELIVERY_NOTE)->set('number', $n)->set('file', $this->pdf())->call('save');
        $doc = Document::where('reference', $n)->firstOrFail();
        $c->call('remove', $doc->id);
        $this->assertNull(Document::find($doc->id));
    }

    public function test_job_page_shows_the_journey_and_paperwork_for_each_role(): void
    {
        $job = $this->job();
        $roles = ['Technician' => User::find($job->assigned_technician_id), 'Service Admin' => User::get()->first(fn ($u) => $u->hasRole('Service Admin')),
            'Supervisor' => User::get()->first(fn ($u) => $u->hasRole('Supervisor')), 'Manager' => User::get()->first(fn ($u) => $u->hasRole('Manager')),
            'Customer' => User::where('customer_id', $job->customer_id)->first()];
        foreach ($roles as $role => $u) {
            if (! $u) {
                continue;
            }
            $this->actingAs($u)->get('/jobs/'.$job->id)->assertOk()->assertSee('Report filed')->assertSee('Certificates and paperwork');
        }
    }

    public function test_certificate_type_names_come_from_settings(): void
    {
        $ict = User::get()->first(fn ($u) => $u->hasRole('ICT'));
        $this->actingAs($ict);
        $before = [Settings::raw('cert_type_1'), Settings::raw('cert_type_2'), Settings::raw('cert_type_3')];
        $c = Livewire::test('settings')->call('show', 'certificates');
        $c->set('values.cert_type_1', 'Same')->set('values.cert_type_2', 'same')->call('save')->assertHasErrors(['values.cert_type_2']);
        $c->set('values.cert_type_1', 'AEA calibration')->set('values.cert_type_2', 'Government verification')->set('values.cert_type_3', 'Third party')->call('save')->assertHasNoErrors();
        $this->assertSame(['AEA calibration', 'Government verification', 'Third party'], Settings::certificateTypes());
        foreach ([1, 2, 3] as $i) {
            Settings::set('cert_type_'.$i, $before[$i - 1] === "Certificate type $i" ? null : $before[$i - 1]);
        }
    }

    private function requestFor(string $cover): \App\Models\ServiceRequest
    {
        $job = $this->job();

        return \App\Models\ServiceRequest::create([
            'reference' => 'RQ-T'.uniqid(),
            'customer_id' => $job->customer_id,
            'fault_description' => 'Scale drifts after warm up, please check.',
            'cover' => $cover,
            'priority' => 'Medium',
            'logged_by_id' => User::get()->first(fn ($u) => $u->hasRole('Service Admin'))->id,
        ]);
    }

    public function test_a_contract_request_becomes_a_job_in_one_step(): void
    {
        $sa = User::get()->first(fn ($u) => $u->hasRole('Service Admin'));
        $tech = User::get()->first(fn ($u) => $u->hasRole('Technician'));
        $this->actingAs($sa);
        $req = $this->requestFor('Contract');

        $c = Livewire::test('requests.show', ['request' => $req]);
        $c->call('assignAndCreate')->assertHasErrors(['technicianId']);
        $c->set('technicianId', (string) $tech->id)->set('dueDate', today()->addDays(2)->toDateString())->call('assignAndCreate')->assertHasNoErrors();

        $req->refresh();
        $this->assertSame('Converted', $req->status);
        $job = $req->workOrder;
        $this->assertNotNull($job);
        $this->assertSame($tech->id, $job->assigned_technician_id);

        // a second click does not make a second job
        Livewire::test('requests.show', ['request' => $req->fresh()])->call('assignAndCreate')->assertForbidden();
        $this->assertSame(1, \App\Models\WorkOrder::where('source_service_request_id', $req->id)->count());
    }

    public function test_a_chargeable_request_is_guided_to_a_quotation_and_the_job_follows_the_lpo(): void
    {
        $sa = User::get()->first(fn ($u) => $u->hasRole('Service Admin'));
        $this->actingAs($sa);
        $req = $this->requestFor('Chargeable');

        $this->get('/requests/'.$req->id)->assertOk()->assertSee('This work is chargeable')->assertSee('Prepare quotation');

        $q = Livewire::test('quotations.create', [])->assertSet('requestId', null);
        $this->get('/quotations/create?request='.$req->id)->assertOk()->assertSee('Pre-filled from the service request');

        // prefill and linking happen on mount, so build the same state the page would
        $quotation = \App\Models\Quotation::create([
            'reference' => 'QT-T'.uniqid(), 'customer_id' => $req->customer_id, 'scope' => $req->fault_description,
            'source_service_request_id' => $req->id, 'currency_code' => 'KES', 'created_by' => $sa->id, 'validity_days' => 30,
        ]);
        $this->assertSame($quotation->id, $req->fresh()->quotation->id);

        $tech = User::get()->first(fn ($u) => $u->hasRole('Technician'));
        $job = $quotation->convertToJob($tech->id, today()->addDay()->toDateString(), 'WO-T'.uniqid());
        $this->assertSame('Converted', $req->fresh()->status);
        $this->actingAs($sa)->get('/requests/'.$req->id)->assertOk()->assertSee($job->reference);
        $this->actingAs($sa)->get('/quotations/'.$quotation->id)->assertOk()->assertSee('LPO filed');
    }

    public function test_quotation_made_from_a_request_is_linked_to_it(): void
    {
        $sa = User::get()->first(fn ($u) => $u->hasRole('Service Admin'));
        $this->actingAs($sa);
        $req = $this->requestFor('Chargeable');

        Livewire::withQueryParams(['request' => $req->id])->test('quotations.create')
            ->assertSet('requestId', $req->id)
            ->assertSet('customerId', (string) $req->customer_id)
            ->assertSet('scope', $req->fault_description)
            ->set('items', [['description' => 'Load cell', 'quantity' => 1, 'rate' => '1000']])
            ->call('submit')->assertHasNoErrors();

        $this->assertSame('Quoted', $req->fresh()->status);
        $this->assertNotNull($req->fresh()->quotation);
    }

    public function test_uploaded_files_are_private_and_open_only_to_people_who_may_see_the_job(): void
    {
        Storage::fake('local');
        $job = $this->job();
        $this->actingAs(User::findOrFail($job->assigned_technician_id));
        $n = 'P'.uniqid();

        Livewire::test('jobs.attachments', ['job' => $job])->call('openForm', Document::TYPE_DELIVERY_NOTE)
            ->set('number', $n)->set('file', $this->pdf())->call('save')->assertHasNoErrors();
        $doc = Document::where('reference', $n)->firstOrFail();
        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('public')->assertMissing($doc->file_path);

        $url = \App\Support\Files::url($doc->file_path);

        auth()->logout();
        $this->get($url)->assertRedirect(); // not signed in

        $this->actingAs(User::findOrFail($job->assigned_technician_id))->get($url)->assertOk();

        $owner = User::where('customer_id', $job->customer_id)->first();
        if ($owner) {
            $this->actingAs($owner)->get($url)->assertOk();
        }

        $stranger = User::whereNotNull('customer_id')->where('customer_id', '!=', $job->customer_id)->first();
        if ($stranger) {
            $this->actingAs($stranger)->get($url)->assertNotFound();
        }

        $this->actingAs(User::role('Service Admin')->firstOrFail())->get('/files/..%2F.env')->assertNotFound();
        $this->actingAs(User::role('Service Admin')->firstOrFail())->get('/files/not/a/known/file.pdf')->assertNotFound();
    }
}
