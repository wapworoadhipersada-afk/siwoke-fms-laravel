<?php

declare(strict_types=1);

namespace Modules\Document\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Document\Domain\Models\Attachment;

/**
 * Finds files on the evidence disk that no database row points to (left behind when an upload was cut off
 * between saving the file and saving the row). By default it only lists them; --delete removes them.
 * Files younger than a day are never touched, because an upload may be in progress right now.
 */
final class PruneOrphanFilesCommand extends Command
{
    protected $signature = 'document:prune-orphans {--delete : Actually remove the files instead of only listing them}';

    protected $description = 'List (or remove) evidence files that no attachment record refers to';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('documents.disk'));

        $known = Attachment::query()->withTrashed()->get(['path', 'thumbnail_path'])
            ->flatMap(fn (Attachment $attachment): array => array_filter([$attachment->path, $attachment->thumbnail_path]))
            ->flip()
            ->all();

        $cutoff = now()->subDay()->getTimestamp();
        $orphans = [];

        foreach ($disk->allFiles('attachments') as $path) {
            if (! isset($known[$path]) && $disk->lastModified($path) < $cutoff) {
                $orphans[] = $path;
            }
        }

        if ($orphans === []) {
            $this->info('Tidak ada file yatim.');

            return self::SUCCESS;
        }

        foreach ($orphans as $path) {
            $this->line(" - {$path}");
        }

        if ($this->option('delete')) {
            $disk->delete($orphans);
            $this->info(count($orphans).' file yatim dihapus.');
        } else {
            $this->warn(count($orphans).' file yatim ditemukan. Jalankan dengan --delete untuk menghapusnya.');
        }

        return self::SUCCESS;
    }
}
