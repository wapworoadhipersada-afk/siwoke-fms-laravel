<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Document\Application\Actions\DeleteAttachment;
use Modules\Document\Application\Actions\StoreAttachment;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Document\Domain\Models\Attachment;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ProblemException;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;
use Modules\Ticket\Http\Api\V1\Requests\UploadAttachmentRequest;

/**
 * Evidence from the technician's phone: photos and signatures for the visit they are working.
 */
final class TicketAttachmentController extends Controller
{
    public function store(UploadAttachmentRequest $request, Ticket $ticket, StoreAttachment $action): JsonResponse
    {
        $user = $this->technician($request);
        $visit = $this->visit($ticket, $user);

        $file = $request->file('file');
        assert($file instanceof UploadedFile);

        $capturedAt = $request->input('captured_at');

        try {
            $attachment = $action->handle(
                $visit,
                $user,
                $file,
                AttachmentKind::from((string) $request->validated('kind')),
                is_string($capturedAt) && $capturedAt !== '' ? CarbonImmutable::parse($capturedAt) : null,
                $request->input('latitude'),
                $request->input('longitude'),
                $this->deviceId($user),
                $request->filled('client_uuid') ? (string) $request->input('client_uuid') : null,
            );
        } catch (AuthorizationException) {
            throw ProblemException::forbidden('cannot_attach', 'Lampiran hanya dapat ditambahkan pada kunjungan yang sedang Anda kerjakan.');
        }

        // A retry of an upload we already have answers 200 with the original, a new file answers 201.
        return response()->json(['data' => $this->payload($attachment)], $attachment->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $this->technician($request);
        $visitIds = TicketVisit::query()->where('ticket_id', $ticket->id)->where('technician_id', $user->id)->pluck('id');

        abort_if($visitIds->isEmpty(), 404);

        return response()->json(['data' => Attachment::query()
            ->where('attachable_type', 'ticket_visit')
            ->whereIn('attachable_id', $visitIds)
            ->orderBy('id')
            ->get()
            ->map(fn (Attachment $attachment): array => $this->payload($attachment))
            ->values()
            ->all()]);
    }

    public function destroy(Request $request, Attachment $attachment, DeleteAttachment $action): Response
    {
        $user = $this->technician($request);

        try {
            $action->handle($attachment, $user);
        } catch (AuthorizationException) {
            throw ProblemException::forbidden('cannot_delete', 'Lampiran ini tidak dapat dihapus.');
        }

        return response()->noContent();
    }

    private function technician(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        abort_unless($user->can(Permissions::TICKET_WORK), 403);

        return $user;
    }

    /**
     * The visit this technician is working on; a ticket they never had does not exist for them.
     */
    private function visit(Ticket $ticket, User $user): TicketVisit
    {
        $visit = TicketVisit::query()->where('ticket_id', $ticket->id)->where('technician_id', $user->id)->orderByDesc('visit_no')->first();

        abort_unless($visit instanceof TicketVisit, 404);

        return $visit;
    }

    private function deviceId(User $user): ?int
    {
        $token = $user->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? $token->getAttribute('device_id') : null;

        return is_numeric($deviceId) ? (int) $deviceId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Attachment $attachment): array
    {
        return [
            'id' => $attachment->ulid,
            'kind' => $attachment->kind->value,
            'kind_label' => $attachment->kind->label(),
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'captured_at' => $attachment->captured_at?->toIso8601String(),
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'url' => route('api.mobile.v1.attachments.show', $attachment),
            'thumbnail_url' => route('api.mobile.v1.attachments.thumbnail', $attachment),
        ];
    }
}
