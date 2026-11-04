<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Domain\Enums\LocationType;

final class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MASTER_LOCATION_MANAGE) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // The type is chosen once, when the location is created, and never changes.
            'type' => [$this->isMethod('POST') ? 'required' : 'prohibited', Rule::enum(LocationType::class)],
            'parent' => ['nullable', 'string', 'size:26'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => 'Kode hanya boleh berisi huruf, angka, titik, minus, dan underscore.'];
    }
}
