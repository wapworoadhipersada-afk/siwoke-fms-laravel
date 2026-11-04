<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\IAM\Domain\Permissions;

final class WarehouseRequest extends FormRequest
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
            'location' => ['required', 'string', 'size:26'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'pic' => ['nullable', 'string', 'size:26'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
