<?php

declare(strict_types=1);

namespace Modules\Document\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Document\Domain\Models\Attachment;

final class VerifyAttachmentsCommand extends Command
{
    protected $signature = 'document:verify {--with-deleted : Also check files of attachments that were deleted}';

    protected $description = 'Check that every stored evidence file still exists and matches the hash recorded when it was saved';

    public function handle(): int
    {
        $query = $this->option('with-deleted') ? Attachment::query()->withTrashed() : Attachment::query();
        $problems = [];
        $checked = 0;

        $query->orderBy('id')->each(function (Attachment $attachment) use (&$problems, &$checked): void {
            $checked++;
            $disk = Storage::disk($attachment->disk);

            if (! $disk->exists($attachment->path)) {
                $problems[] = "Lampiran {$attachment->ulid}: file hilang ({$attachment->path}).";

                return;
            }

            if (hash('sha256', (string) $disk->get($attachment->path)) !== $attachment->sha256) {
                $problems[] = "Lampiran {$attachment->ulid}: isi file berubah dari saat disimpan.";
            }
        });

        if ($problems === []) {
            $this->info("{$checked} lampiran diperiksa, semuanya utuh.");

            return self::SUCCESS;
        }

        $this->error(count($problems).' masalah ditemukan dari '.$checked.' lampiran:');

        foreach ($problems as $problem) {
            $this->line(" - {$problem}");
        }

        return self::FAILURE;
    }
}
