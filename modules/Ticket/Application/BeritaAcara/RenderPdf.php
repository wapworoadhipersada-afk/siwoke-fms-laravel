<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\BeritaAcara;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Modules\Document\Domain\Models\Attachment;

/**
 * Draws a snapshot as a PDF. Photos and signatures are read from private storage and embedded as data, so the
 * renderer never fetches anything over the network. Each file is checked against the hash recorded in the
 * snapshot, and a file that no longer matches is left out rather than printed on an official report.
 */
final class RenderPdf
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function handle(array $snapshot): string
    {
        $timezone = (string) config('tickets.berita_acara.timezone');
        $evidence = is_array($snapshot['evidence'] ?? null) ? $snapshot['evidence'] : [];
        $maxPhotos = (int) config('tickets.berita_acara.max_photos');

        $photos = [];
        $signatures = [];
        $omitted = 0;

        foreach ($evidence as $item) {
            $isSignature = str_starts_with((string) $item['kind'], 'signature');

            if (! $isSignature && count($photos) >= $maxPhotos) {
                $omitted++;

                continue;
            }

            $image = $this->load($item);

            if ($image === null) {
                continue;
            }

            if ($isSignature) {
                $signatures[(string) $item['kind']] = $image;
            } else {
                $photos[] = ['label' => $item['label'], 'src' => $image, 'taken' => $this->local($item['captured_at'] ?? null, $timezone)];
            }
        }

        return Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'dpi' => 110])
            ->loadView('ticket::berita-acara', [
                'ba' => $snapshot,
                'photos' => $photos,
                'omittedPhotos' => $omitted,
                'merchantSignature' => $signatures['signature_merchant'] ?? null,
                'technicianSignature' => $signatures['signature_technician'] ?? null,
                'when' => fn (?string $time): string => $this->local($time, $timezone) ?? '-',
            ])
            ->setPaper('a4')
            ->output();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function load(array $item): ?string
    {
        $file = Attachment::query()->where('ulid', (string) $item['ulid'])->first();

        if (! $file instanceof Attachment) {
            return null;
        }

        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            return null;
        }

        // The full file is the one with a recorded hash; if it was swapped or damaged, it is not printed.
        if (! hash_equals((string) $item['sha256'], hash('sha256', (string) $disk->get($file->path)))) {
            return null;
        }

        $isSignature = str_starts_with((string) $item['kind'], 'signature');

        // Photos use their small copy: a report needs to be readable, not full resolution, and stays light to email.
        if (! $isSignature && $file->thumbnail_path !== null && $disk->exists($file->thumbnail_path)) {
            return 'data:image/jpeg;base64,'.base64_encode((string) $disk->get($file->thumbnail_path));
        }

        return 'data:'.$file->mime.';base64,'.base64_encode((string) $disk->get($file->path));
    }

    private function local(?string $time, string $timezone): ?string
    {
        return $time === null ? null : CarbonImmutable::parse($time)->setTimezone($timezone)->format('d/m/Y H:i');
    }
}
