<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\IAM\Domain\Permissions;

final class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MASTER_VENDOR_MANAGE) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => 'Kode hanya boleh berisi huruf, angka, minus, dan underscore.'];
    }
}
