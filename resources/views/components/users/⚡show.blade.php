<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use App\Models\User;
use App\Models\TechnicianDocument;
use App\Mail\AccountCredentialsMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new #[Layout('layouts.app', ['title' => 'User account'])] class extends Component
{
    use WithFileUploads;

    public User $account;
    public ?string $generatedPassword = null;
    public ?bool $emailSent = null;

    public bool $addingDocument = false;
    public string $documentType = 'Trade certification';
    public string $issuedAt = '';
    public string $validityMonths = '12';
    public $documentFile = null;

    public function mount(User $account): void
    {
        $this->authorize('view', $account);
        $this->account = $account->load(['branch', 'customer', 'technicianDocuments']);
    }

    public function toggleAddDocument(): void
    {
        $this->authorize('manage', $this->account);

        $this->addingDocument = ! $this->addingDocument;
        $this->documentType = 'Trade certification';
        $this->issuedAt = '';
        $this->validityMonths = '12';
        $this->documentFile = null;
    }

    public function addDocument(): void
    {
        $this->authorize('manage', $this->account);

        $validated = $this->validate([
            'documentType' => ['required', 'in:Medical cover / insurance,Driving licence,Trade certification,Medical certificate'],
            'issuedAt' => ['required', 'date'],
            'validityMonths' => ['required', 'integer', 'min:1'],
            'documentFile' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $path = $this->documentFile?->store('technician-documents', 'public');

        TechnicianDocument::create([
            'technician_id' => $this->account->id,
            'document_type' => $validated['documentType'],
            'issued_at' => $validated['issuedAt'],
            'validity_months' => $validated['validityMonths'],
            'file_path' => $path,
        ]);

        $this->account->load('technicianDocuments');
        $this->addingDocument = false;
        $this->documentType = 'Trade certification';
        $this->issuedAt = '';
        $this->validityMonths = '12';
        $this->documentFile = null;
    }

    public function deleteDocument(int $documentId): void
    {
        $this->authorize('manage', $this->account);

        $document = $this->account->technicianDocuments->firstWhere('id', $documentId);

        if ($document) {
            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }
            $document->delete();
            $this->account->load('technicianDocuments');
        }
    }

    public function toggleLock(): void
    {
        $this->authorize('manage', $this->account);

        $this->account->update([
            'status' => $this->account->status === 'Active' ? 'Locked' : 'Active',
        ]);
        $this->account->refresh();
    }

    public function resetPassword(): void
    {
        $this->authorize('manage', $this->account);

        $temp = Str::password(12);

        $this->account->update([
            'password' => Hash::make($temp),
            'must_change_password' => true,
        ]);
        $this->generatedPassword = $temp;

        try {
            Mail::to($this->account->email)->send(new AccountCredentialsMail($this->account, $temp, false));
            $this->emailSent = true;
        } catch (\Throwable $e) {
            $this->emailSent = false;
        }
    }
};
?>

<div>
    <a href="/users" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-900">
        <x-icon name="arrow-right" class="h-3.5 w-3.5 rotate-180" />
        Back to accounts
    </a>

    <div class="mb-4 flex items-start justify-between">
        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white">
                {{ collect(explode(' ', $account->name))->map(fn ($p) => $p[0] ?? '')->take(2)->implode('') }}
            </div>
            <div>
                <h1 class="text-xl font-semibold text-neutral-900">{{ $account->name }}</h1>
                <p class="text-sm text-neutral-500">{{ $account->email }}</p>
            </div>
        </div>
        <span @class(['pill-success' => $account->status === 'Active', 'pill-danger' => $account->status !== 'Active'])>
            {{ $account->status }}
        </span>
    </div>

    <div class="card mb-4">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-neutral-500">Role</dt>
                <dd class="text-neutral-900">{{ $account->getRoleNames()->first() }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Phone</dt>
                <dd class="text-neutral-900">{{ $account->phone ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">{{ $account->customer ? 'Customer' : 'Branch' }}</dt>
                <dd class="text-neutral-900">{{ $account->customer->name ?? $account->branch->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-neutral-500">Last seen</dt>
                <dd class="text-neutral-900">{{ $account->last_seen_at?->diffForHumans() ?? 'Never' }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="text-neutral-500">Account created</dt>
                <dd class="text-neutral-900">{{ $account->created_at->format('d M Y') }}</dd>
            </div>
        </dl>
    </div>

    @if ($generatedPassword)
        <div class="card mb-4" style="background-color: var(--color-info-50); border-color: var(--color-info-200)">
            @if ($emailSent)
                <h2 class="mb-1 text-sm font-semibold text-info-800">Password reset</h2>
                <p class="text-xs text-info-700">
                    A new temporary password was emailed to {{ $account->email }}. They'll be asked to set their own as soon as they sign in.
                </p>
            @else
                <h2 class="mb-1 text-sm font-semibold text-info-800">Password reset &mdash; email could not be sent</h2>
                <p class="mb-2 text-xs text-info-700">
                    Mail isn't set up yet, or the send failed, so relay this to {{ $account->name }} yourself. It won't be shown again.
                </p>
                <p class="rounded-[var(--radius-sm)] bg-white px-3 py-2 font-mono text-sm text-neutral-900">{{ $generatedPassword }}</p>
            @endif
        </div>
    @endif

    @if ($account->hasRole('Technician'))
        <div class="card mb-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-neutral-900">Certificates &amp; documents</h2>
                @can('manage', $account)
                    <button type="button" wire:click="toggleAddDocument" class="text-xs font-semibold text-primary-600 hover:text-primary-700">
                        {{ $addingDocument ? 'Cancel' : '+ Add a document' }}
                    </button>
                @endcan
            </div>

            @can('manage', $account)
                @if ($addingDocument)
                    <form wire:submit="addDocument" class="mb-4 space-y-3 rounded-[var(--radius-md)] border border-neutral-200 p-3">
                        <div>
                            <label class="label">Document type</label>
                            <select wire:model="documentType" class="input">
                                <option>Medical cover / insurance</option>
                                <option>Driving licence</option>
                                <option>Trade certification</option>
                                <option>Medical certificate</option>
                            </select>
                            @error('documentType') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="label">Issued on</label>
                                <input wire:model="issuedAt" type="date" class="input">
                                @error('issuedAt') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="label">Valid for (months)</label>
                                <input wire:model="validityMonths" type="number" min="1" class="input">
                                @error('validityMonths') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div>
                            <label class="label">Scanned copy (optional)</label>
                            <input wire:model="documentFile" type="file" accept=".pdf,.jpg,.jpeg,.png" class="input">
                            @error('documentFile') <p class="mt-1 text-xs text-critical-700">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" wire:loading.attr="disabled" wire:target="addDocument,documentFile" class="btn-primary w-full">
                            Save document
                        </button>
                    </form>
                @endif
            @endcan

            <div class="divide-y divide-neutral-100">
                @forelse ($account->technicianDocuments->sortByDesc(fn ($d) => $d->percentUsed()) as $doc)
                    <div class="flex items-center justify-between gap-3 py-2.5">
                        <x-expiry-ring
                            :percent="$doc->percentUsedCapped()"
                            :stage="$doc->expiryStage()"
                            :title="$doc->document_type"
                            :expiresAt="$doc->expiresAt()->format('d M Y')" />
                        <div class="flex shrink-0 items-center gap-3">
                            @if ($doc->file_path)
                                <a href="{{ Storage::url($doc->file_path) }}" target="_blank" class="text-xs font-semibold text-primary-600 hover:text-primary-700">View</a>
                            @endif
                            @can('manage', $account)
                                <button type="button" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="Remove this document?" class="text-xs font-semibold text-critical-700 hover:text-critical-800">
                                    Remove
                                </button>
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="py-2 text-sm text-neutral-500">No documents on file.</p>
                @endforelse
            </div>
        </div>
    @endif

    @can('manage', $account)
        <div class="flex gap-2">
            <button wire:click="toggleLock" wire:loading.attr="disabled" wire:target="toggleLock" class="btn-outline flex-1">
                {{ $account->status === 'Active' ? 'Lock account' : 'Unlock account' }}
            </button>
            <button wire:click="resetPassword" wire:loading.attr="disabled" wire:target="resetPassword" class="btn-primary flex-1">
                Reset password
            </button>
        </div>
    @endcan
</div>
