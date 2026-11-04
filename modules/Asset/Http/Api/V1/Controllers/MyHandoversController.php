<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\RejectHandover;
use Modules\Asset\Application\Services\HolderLabeler;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetHandoverItem;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ProblemException;

final class MyHandoversController extends Controller
{
    public function __construct(private readonly HolderLabeler $labeler) {}

    /**
     * Assets other people are waiting for the technician to confirm.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $handovers = AssetHandover::query()
            ->with(['items.asset', 'items.model', 'requester'])
            ->where('status', HandoverStatus::Pending->value)
            ->where('to_holder_type', HolderType::User->value)
            ->where('to_holder_id', $user->id)
            ->orderBy('id')
            ->get();

        $labels = $this->labeler->labels($handovers->map(fn (AssetHandover $handover): Holder => $handover->from()));

        return response()->json(['data' => $handovers->map(fn (AssetHandover $handover): array => [
            'id' => $handover->ulid,
            'type' => $handover->type->value,
            'from' => $this->labeler->resolve($labels, $handover->from()),
            'requested_by' => $handover->requester->name,
            'requested_at' => $handover->requested_at->toIso8601String(),
            'note' => $handover->note,
            'items' => $handover->items->map(fn (AssetHandoverItem $item): array => [
                'serial_number' => $item->asset instanceof Asset ? $item->asset->serial_number : null,
                'model' => $item->model->displayName(),
                'quantity' => $item->quantity,
            ])->values()->all(),
        ])->values()->all()]);
    }

    /**
     * The technician confirms they have physically received the assets. Custody changes now.
     */
    public function accept(Request $request, AssetHandover $handover, AcceptHandover $action): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            $movement = $action->handle($handover, $user, $data['note'] ?? null, $data['latitude'] ?? null, $data['longitude'] ?? null, $this->deviceId($user));
        } catch (AuthorizationException) {
            throw ProblemException::forbidden('not_the_receiver', 'Anda bukan penerima serah-terima ini.');
        }

        return response()->json(['data' => ['movement_no' => $movement->movement_no, 'status' => HandoverStatus::Accepted->value]]);
    }

    /**
     * The technician refuses what was offered, with a reason. The assets go back to the sender.
     */
    public function reject(Request $request, AssetHandover $handover, RejectHandover $action): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $action->handle($handover, $user, $data['reason']);
        } catch (AuthorizationException) {
            throw ProblemException::forbidden('not_the_receiver', 'Anda bukan penerima serah-terima ini.');
        }

        return response()->json(['data' => ['status' => HandoverStatus::Rejected->value]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        abort_unless($user->can(Permissions::ASSET_TRANSFER_RECEIVE), 403);

        return $user;
    }

    private function deviceId(User $user): ?int
    {
        $token = $user->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? $token->getAttribute('device_id') : null;

        return is_numeric($deviceId) ? (int) $deviceId : null;
    }
}
