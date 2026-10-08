<?php

namespace App\Http\Controllers;

use App\Models\CertificateDetail;
use App\Models\Contract;
use App\Models\Document;
use App\Models\LpoDetail;
use App\Models\ServiceReportDetail;
use App\Models\TechnicianDocument;
use App\Models\User;
use App\Support\Files;
use Illuminate\Support\Facades\Storage;

/** Serves an uploaded file only to someone allowed to see the record it belongs to. */
class FileController extends Controller
{
    public function show(string $path)
    {
        $path = ltrim($path, '/');

        abort_if($path === '' || str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '\\'), 404);

        $user = auth()->user();
        abort_unless($user && $this->mayOpen($user, $path), 404);

        $disk = collect([Files::DISK, 'public'])->first(fn ($d) => Storage::disk($d)->exists($path));
        abort_unless($disk, 404);

        return Storage::disk($disk)->response($path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The file is open to whoever may open the record that owns it. Anything unowned is refused. */
    protected function mayOpen(User $user, string $path): bool
    {
        $documents = collect();

        $documents = $documents
            ->merge(Document::where('file_path', $path)->get())
            ->merge(CertificateDetail::where('file_path', $path)->with('document')->get()->pluck('document'))
            ->merge(LpoDetail::where('file_path', $path)->with('document')->get()->pluck('document'))
            ->merge(ServiceReportDetail::where('delivery_note_path', $path)->orWhere('incident_photo_path', $path)->with('document')->get()->pluck('document'))
            ->filter();

        if ($documents->contains(fn ($d) => $user->can('view', $d))) {
            return true;
        }

        if (Contract::where('scan_file_path', $path)->get()->contains(fn ($c) => $user->can('view', $c))) {
            return true;
        }

        $owners = TechnicianDocument::where('file_path', $path)->with('technician')->get();

        return $owners->contains(fn ($d) => $d->technician_id === $user->id
            || $user->hasAnyRole(['Manager', 'Supervisor', 'Service Admin', 'ICT', 'Super Admin']));
    }
}
