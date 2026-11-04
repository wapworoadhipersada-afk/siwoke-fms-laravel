<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Api\V1\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RefreshRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'size:64'],
            'device_uid' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
