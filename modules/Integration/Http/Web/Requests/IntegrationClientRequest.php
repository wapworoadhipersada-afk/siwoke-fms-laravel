<?php

declare(strict_types=1);

namespace Modules\Integration\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\IAM\Domain\Permissions;

final class IntegrationClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::INTEGRATION_CLIENT_MANAGE) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'vendor' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:100'],
            // One address or range per line, e.g. 203.0.113.10 or 203.0.113.0/24. Empty allows any address.
            'allowed_ips' => ['nullable', 'string', 'max:1000'],
            'webhook_url' => ['nullable', 'url', 'max:500'],
        ];
    }

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->input('allowed_ips')) ?: [])));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->addresses() as $address) {
                $base = explode('/', $address)[0];

                if (filter_var($base, FILTER_VALIDATE_IP) === false) {
                    $validator->errors()->add('allowed_ips', "Alamat IP tidak valid: {$address}");
                }
            }
        });
    }
}
