<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\IAM\Domain\Permissions;

final class MerchantSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MASTER_MERCHANT_MANAGE) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'location' => ['required', 'string', 'size:26'],
            'name' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pic_name' => ['nullable', 'string', 'max:255'],
            'pic_phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
