<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Asset\Domain\Enums\AssetCondition;
use Modules\IAM\Domain\Permissions;

final class ReceiveAssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ASSET_CREATE) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'asset_model' => ['required', 'string', 'size:26'],
            'vendor' => ['required', 'string', 'size:26'],
            'warehouse' => ['required', 'string', 'size:26'],
            // One serial per line (or comma separated) for units; a quantity for consumables. The action checks which applies.
            'serials' => ['nullable', 'string', 'max:50000'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'condition' => ['nullable', Rule::enum(AssetCondition::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
