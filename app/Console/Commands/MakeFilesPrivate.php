<?php

namespace App\Console\Commands;

use App\Support\Files;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MakeFilesPrivate extends Command
{
    protected $signature = 'files:make-private';

    protected $description = 'Move uploaded paperwork from the public web folder to private storage';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk(Files::DISK);
        $moved = 0;

        foreach (['service-reports', 'job-documents', 'lpo-documents', 'technician-documents', 'contracts'] as $dir) {
            foreach ($public->allFiles($dir) as $path) {
                $private->put($path, $public->get($path));
                $public->delete($path);
                $moved++;
            }
        }

        $this->info("Moved {$moved} file(s) to private storage.");

        return self::SUCCESS;
    }
}
