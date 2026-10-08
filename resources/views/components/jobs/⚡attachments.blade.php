<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\CertificateDetail;
use App\Models\Document;
use App\Models\Equipment;
use App\Models\WorkOrder;
use App\Services\Audit;
use App\Services\WorkflowNotifier;
use App\Support\Rules;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

new class extends Component
{
    use WithFileUploads;

    public WorkOrder $job;

    // Which form is open: a kind, plus for certificates the type it is for.
    public ?string $open = null;
    public string $slot = '';

    public $file = null;
    public string $number = '';
    public string $title = '';
    public string $equipmentId = '';
    public string $issuedAt = '';
    public string $expiresAt = '';

    public function mount(WorkOrder $job): void
    {
        $this->job = $job;
    }

    /** kind => [heading, single name, needs a number on the paper, needs a title] */
    public function labels(): array
    {
        return [
            Document::TYPE_SCAN => ['Signed service report (hard copy)', 'Signed service report', false, false],
            Document::TYPE_CERTIFICATE => ['Calibration certificates', 'Calibration certificate', true, false],
            Document::TYPE_VOUCHER => ['Maintenance voucher', 'Maintenance voucher', true, false],
            Document::TYPE_DELIVERY_NOTE => ['Delivery note', 'Delivery note', true, false],
            Document::TYPE_OTHER => ['Other documents', 'Document', false, true],
        ];
    }

    public function openForm(string $kind, string $slot = ''): void
    {
        abort_unless(array_key_exists($kind, $this->labels()), 404);
        $this->authorize('attach', [$this->job, $kind]);

        if ($kind === Document::TYPE_CERTIFICATE) {
            abort_unless(in_array($slot, Settings::certificateTypes(), true), 404);
        }

        $this->resetValidation();
        $this->reset(['file', 'number', 'title', 'expiresAt']);
        $this->open = $kind;
        $this->slot = $kind === Document::TYPE_CERTIFICATE ? $slot : '';
        $this->equipmentId = (string) ($this->job->equipment_id ?? '');
        $this->issuedAt = today()->toDateString();
    }

    public function cancel(): void
    {
        $this->open = null;
        $this->slot = '';
        $this->reset(['file', 'number', 'title', 'expiresAt']);
        $this->resetValidation();
    }

    /** A reference for papers that carry no number of their own, unique across all documents. */
    protected function autoReference(string $prefix): string
    {
        $n = Document::where('work_order_id', $this->job->id)->where('type', $this->open)->count() + 1;
        do {
            $ref = $prefix.'-'.$this->job->reference.'-'.$n++;
        } while (Document::where('reference', $ref)->exists());

        return $ref;
    }

    public function save(): void
    {
        $kind = $this->open;
        abort_unless($kind && array_key_exists($kind, $this->labels()), 404);
        $this->authorize('attach', [$this->job, $kind]);
        [, $single, $needsNumber, $needsTitle] = $this->labels()[$kind];

        $rules = ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']];
        if ($needsNumber) {
            $rules['number'] = Rules::text(40, true, 2);
        }
        if ($needsTitle) {
            $rules['title'] = Rules::text(120, true, 2);
        }

        if ($kind === Document::TYPE_CERTIFICATE) {
            abort_unless(in_array($this->slot, Settings::certificateTypes(), true), 404);
            $rules += [
                'equipmentId' => ['nullable', Rule::exists('equipment', 'id')->where('customer_id', $this->job->customer_id)],
                'issuedAt' => ['required', 'date', 'before_or_equal:today', 'after:2000-01-01'],
                'expiresAt' => ['required', 'date', 'after:issuedAt', 'before:2100-01-01'],
            ];
        }

        $this->validate($rules, [
            'file.required' => 'Choose the file to attach.',
            'file.mimes' => 'The file must be a PDF or a photo (JPG or PNG).',
            'file.max' => 'The file is too large. The most we can take is 10 MB.',
            'file.uploaded' => 'The file did not upload. Check your connection and choose it again.',
            'expiresAt.after' => 'The expiry date must come after the date it was issued.',
            'issuedAt.before_or_equal' => 'The issue date cannot be in the future.',
        ], [
            'file' => 'file',
            'number' => 'number',
            'title' => 'name',
            'equipmentId' => 'machine',
            'issuedAt' => 'issue date',
            'expiresAt' => 'expiry date',
        ]);

        $existing = null;
        $reuse = false;
        if ($needsNumber) {
            $number = trim($this->number);
            $existing = Document::where('reference', $number)->first();

            // A voucher already created from the service report can simply receive its signed copy.
            $reuse = $existing
                && $existing->type === $kind
                && $kind === Document::TYPE_VOUCHER
                && $existing->work_order_id === $this->job->id
                && ! $existing->file_path;

            if ($existing && ! $reuse) {
                $this->addError('number', 'That number is already used on another document. Check the number on the paper.');

                return;
            }
        } else {
            $number = $this->autoReference($kind === Document::TYPE_SCAN ? 'SR' : 'DOC');
        }

        $path = $this->file->store('job-documents', 'public');
        $name = mb_substr($this->file->getClientOriginalName(), 0, 255);

        $document = $reuse ? $existing : Document::create([
            'reference' => $number,
            'type' => $kind,
            'title' => $needsTitle ? trim($this->title) : null,
            'work_order_id' => $this->job->id,
            'customer_id' => $this->job->customer_id,
            'status' => 'Filed',
            'filed_by' => auth()->id(),
        ]);
        $document->update(['file_path' => $path, 'file_name' => $name]);

        if ($kind === Document::TYPE_CERTIFICATE) {
            CertificateDetail::create([
                'document_id' => $document->id,
                'equipment_id' => $this->equipmentId !== '' ? (int) $this->equipmentId : null,
                'certificate_type' => $this->slot,
                'issued_at' => $this->issuedAt,
                'expires_at' => $this->expiresAt,
                'file_path' => $path,
            ]);

            WorkflowNotifier::customer(
                $this->job->customer,
                'A calibration certificate is ready',
                ["{$this->slot} {$number} for job {$this->job->reference} has been added to your account."],
                url("/documents/{$document->id}"),
                'View certificate',
            );
        }

        Audit::record('created', 'Attached '.$single.' '.$number.' to job '.$this->job->reference, $document);

        $this->cancel();
        session()->flash('attached', $single.' attached.');
    }

    public function remove(int $id): void
    {
        $doc = Document::where('work_order_id', $this->job->id)
            ->whereIn('type', array_keys($this->labels()))
            ->whereNotNull('file_path')
            ->findOrFail($id);

        // Only the person who attached it, or a Service Admin, may take it off again.
        abort_unless(
            auth()->user()->can('attach', [$this->job, $doc->type]) && ($doc->filed_by === auth()->id() || auth()->user()->hasRole('Service Admin')),
            403
        );

        Storage::disk('public')->delete($doc->file_path);
        Audit::record('deleted', 'Removed '.$this->labels()[$doc->type][1].' '.$doc->reference.' from job '.$this->job->reference, $doc);
        $doc->delete();
    }

    public function with(): array
    {
        $user = auth()->user();
        $scope = Document::scopeForRole($user->roles->first()?->name ?? '');
        $kinds = array_intersect_key($this->labels(), array_flip($scope));

        $docs = $this->job->documents()
            ->whereIn('type', array_keys($kinds))
            ->with(['certificateDetail.equipment', 'filedBy'])
            ->latest()
            ->get()
            ->groupBy('type');

        $reportNote = null;
        if (isset($kinds[Document::TYPE_DELIVERY_NOTE])) {
            $reportNote = $this->job->documents()->where('type', Document::TYPE_REPORT)->with('reportDetail')->latest()->first()?->reportDetail?->delivery_note_path;
        }

        // Certificates sit under the three names ICT chose. A certificate saved under an
        // older name keeps showing, in a group of its own.
        $names = Settings::certificateTypes();
        $certs = $docs[Document::TYPE_CERTIFICATE] ?? collect();
        $groups = [];
        foreach ($names as $nm) {
            $groups[$nm] = $certs->filter(fn ($d) => $d->certificateDetail?->certificate_type === $nm);
        }
        foreach ($certs->filter(fn ($d) => ! in_array($d->certificateDetail?->certificate_type, $names, true))->groupBy(fn ($d) => $d->certificateDetail?->certificate_type ?: 'Certificate') as $nm => $list) {
            $groups[$nm] = $list;
        }

        return [
            'docs' => $docs,
            'kinds' => $kinds,
            'certGroups' => $groups,
            'liveNames' => $names,
            'reportNote' => $reportNote,
            'machines' => $this->open === Document::TYPE_CERTIFICATE
                ? Equipment::where('customer_id', $this->job->customer_id)->orderBy('model')->orderBy('serial_number')->limit(200)->get(['id', 'model', 'serial_number'])
                : collect(),
        ];
    }
};
?>

<div class="card mb-4">
    <h2 class="text-sm font-semibold text-neutral-900">Certificates and paperwork</h2>
    <p class="mb-3 text-xs text-neutral-500">Scanned copies for this job, kept by type. Customers can see certificates, vouchers and delivery notes on their account.</p>

    @if (session('attached'))
        <div class="mb-3 rounded-lg px-3 py-2 text-sm" style="background: var(--color-success-50, #f0fdf4); color: var(--color-success-700, #15803d)">{{ session('attached') }}</div>
    @endif

    @foreach ($kinds as $kind => [$title, $single, $needsNumber, $needsTitle])
        @php
            $items = $docs[$kind] ?? collect();
            $isCert = $kind === \App\Models\Document::TYPE_CERTIFICATE;
        @endphp
        <div class="border-t border-neutral-100 py-3" wire:key="kind-{{ $kind }}">
            <div class="mb-2 flex items-center justify-between gap-2">
                <p class="text-sm font-semibold text-neutral-900">{{ $title }}</p>
                @if (! $isCert)
                    @can('attach', [$job, $kind])
                        @if ($open !== $kind)
                            <button type="button" wire:click="openForm('{{ $kind }}')" class="btn-outline" style="padding: 0.35rem 0.75rem; min-height: 0">+ Attach</button>
                        @endif
                    @endcan
                @endif
            </div>

            @if ($isCert)
                @foreach ($certGroups as $name => $list)
                    <div class="mb-2 rounded-lg px-3 py-2" style="background: var(--color-neutral-50, #fafafa); border: 1px solid var(--color-neutral-100, #f4f4f5)" wire:key="slot-{{ $loop->index }}">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-medium text-neutral-900">{{ $name }}</p>
                            @if (in_array($name, $liveNames, true))
                                @can('attach', [$job, $kind])
                                    @if (! ($open === $kind && $slot === $name))
                                        <button type="button" wire:click="openForm('{{ $kind }}', @js($name))" class="btn-outline" style="padding: 0.3rem 0.7rem; min-height: 0">+ Attach</button>
                                    @endif
                                @endcan
                            @endif
                        </div>

                        @foreach ($list as $doc)
                            @include('partials.attachment-row', ['doc' => $doc, 'single' => $single, 'kind' => $kind, 'job' => $job])
                        @endforeach

                        @if ($list->isEmpty() && ! ($open === $kind && $slot === $name))
                            <p class="text-xs text-neutral-400">None attached yet.</p>
                        @endif

                        @if ($open === $kind && $slot === $name)
                            @include('partials.attachment-form', ['kind' => $kind, 'single' => $single, 'needsNumber' => $needsNumber, 'needsTitle' => $needsTitle])
                        @endif
                    </div>
                @endforeach
            @else
                @foreach ($items as $doc)
                    @include('partials.attachment-row', ['doc' => $doc, 'single' => $single, 'kind' => $kind, 'job' => $job])
                @endforeach

                @if ($kind === \App\Models\Document::TYPE_DELIVERY_NOTE && $reportNote)
                    <div class="flex items-center justify-between gap-2 py-1.5">
                        <p class="text-sm text-neutral-900">Delivery note from the service report</p>
                        <a href="{{ Storage::url($reportNote) }}" target="_blank" rel="noopener" class="text-xs font-medium text-primary-700 hover:underline">Open file</a>
                    </div>
                @endif

                @if ($items->isEmpty() && ! ($kind === \App\Models\Document::TYPE_DELIVERY_NOTE && $reportNote) && $open !== $kind)
                    <p class="text-xs text-neutral-400">Nothing attached yet.</p>
                @endif

                @if ($open === $kind)
                    @include('partials.attachment-form', ['kind' => $kind, 'single' => $single, 'needsNumber' => $needsNumber, 'needsTitle' => $needsTitle])
                @endif
            @endif
        </div>
    @endforeach
</div>
