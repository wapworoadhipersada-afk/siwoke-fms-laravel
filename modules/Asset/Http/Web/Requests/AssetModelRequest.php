<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\IAM\Domain\Permissions;

final class AssetModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ASSET_CATALOG_MANAGE) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // The category fixes whether units or balances are tracked, so it is chosen once.
            'category' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'string', 'size:26'],
            'brand' => ['required', 'string', 'max:100'],
            'model_name' => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
