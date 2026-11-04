<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\IAM\Domain\Permissions;

final class MerchantRequest extends FormRequest
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
            // A merchant's vendor is chosen once, when it is created.
            'vendor' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'string', 'size:26'],
            'merchant_code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:100'],
        ];
    }
}
