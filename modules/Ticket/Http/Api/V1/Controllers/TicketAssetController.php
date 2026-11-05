<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\IAM\Domain\Permissions;
use Modules\Ticket\Application\Actions\RecordAssetLine;
use Modules\Ticket\Application\Actions\RemoveAssetLine;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Domain\Enums\AssetLineAction;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketAssetLine;
use Modules\Ticket\Domain\Models\TicketVisit;
use Modules\Ticket\Http\Api\V1\Requests\RecordAssetLineRequest;

/**
 * The technician's note of which assets they installed, pulled out or used on a visit.
 * They are applied to the asset ledger only when the visit finishes.
 */
final class TicketAssetController extends Controller
{
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $this->technician($request);
        $visit = $this->visit($ticket, $user);

        $lines = TicketAssetLine::query()
            ->where('ticket_id', $ticket->id)
            ->whereIn('visit_id', TicketVisit::query()->where('ticket_id', $ticket->id)->where('technician_id', $user->id)->select('id'))
            ->with(['asset', 'assetModel'])
            ->orderBy('id')
            ->get();

        // What stands at this TID now, so the technician can pick what to pull out.
        $atTerminal = Asset::query()
            ->where('holder_type', HolderType::Terminal->value)
            ->where('holder_id', $ticket->terminal_id)
            ->where('status', AssetStatus::Installed->value)
            ->with('model')
            ->get();

        return response()->json(['data' => [
            'lines' => $lines->map(fn (TicketAssetLine $line): array => $this->payload($line))->values()->all(),
            'installed_here' => $atTerminal->map(fn (Asset $asset): array => [
                'id' => $asset->ulid,
                'serial_number' => $asset->serial_number,
                'model' => $asset->model->displayName(),
            ])->values()->all(),
            'visit_open' => $visit->isOpen(),
        ]]);
    }

    public function store(RecordAssetLineRequest $request, Ticket $ticket, RecordAssetLine $action): JsonResponse
    {
        $user = $this->technician($request);
        $this->visit($ticket, $user);

        $asset = $request->filled('asset') ? Asset::query()->where('ulid', (string) $request->input('asset'))->first() : null;
        $model = $request->filled('asset_model') ? AssetModel::query()->where('ulid', (string) $request->input('asset_model'))->first() : null;

        // An unknown or foreign id is simply "not found" for this technician; it never confirms that it exists elsewhere.
        abort_if($request->filled('asset') && ! $asset instanceof Asset, 404);
        abort_if($request->filled('asset_model') && ! $model instanceof AssetModel, 404);

        $line = $action->handle(
            $ticket,
            $user,
            AssetLineAction::from((string) $request->validated('action')),
            $asset,
            $model,
            (int) ($request->validated('quantity') ?? 1),
            $request->validated('notes'),
            new ActionContext(deviceId: $this->deviceId($user), clientUuid: $request->filled('client_uuid') ? (string) $request->input('client_uuid') : null),
        );

        return response()->json(['data' => $this->payload($line->load(['asset', 'assetModel']))], $line->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, Ticket $ticket, string $line, RemoveAssetLine $action): Response
    {
        $user = $this->technician($request);
        $this->visit($ticket, $user);

        $model = TicketAssetLine::query()->where('ticket_id', $ticket->id)->where('ulid', $line)->first();
        abort_unless($model instanceof TicketAssetLine, 404);

        $action->handle($ticket, $model, $user, new ActionContext(deviceId: $this->deviceId($user)));

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
    private function payload(TicketAssetLine $line): array
    {
        return [
            'id' => $line->ulid,
            'action' => $line->action->value,
            'action_label' => $line->action->label(),
            'asset' => $line->asset === null ? null : ['id' => $line->asset->ulid, 'serial_number' => $line->asset->serial_number],
            'model' => $line->assetModel->displayName(),
            'quantity' => $line->quantity,
            'notes' => $line->notes,
            'status' => $line->isApplied() ? 'applied' : ($line->voided_at !== null ? 'void' : 'planned'),
            'created_at' => $line->created_at?->toIso8601String(),
        ];
    }
}
