<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Api\V1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ticket\Domain\Enums\AssetLineAction;

final class RecordAssetLineRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(AssetLineAction::class)],
            // Install and uninstall name one unit; a consumable names its kind and a quantity.
            'asset' => ['required_unless:action,consume', 'nullable', 'string', 'size:26'],
            'asset_model' => ['required_if:action,consume', 'nullable', 'string', 'size:26'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }
}
