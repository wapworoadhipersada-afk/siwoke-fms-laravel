<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Asset\Domain\Enums\MovementType;

final class MoveAssetRequest extends FormRequest
{
    /**
     * Movements an office user may record directly on a single asset. Custody changes between people
     * go through a handover, and installing at a merchant belongs to the ticket flow.
     *
     * @var list<MovementType>
     */
    public const ALLOWED = [MovementType::SendToRepair, MovementType::ReturnFromRepair, MovementType::MarkLost, MovementType::Retire];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(MovementType::class)->only(self::ALLOWED)],
            'warehouse' => ['required_if:type,return_from_repair', 'nullable', 'string', 'size:26'],
            'notes' => ['required_if:type,mark_lost,retire', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['notes.required_if' => 'Alasan wajib diisi.', 'warehouse.required_if' => 'Pilih gudang tujuan.'];
    }
}
