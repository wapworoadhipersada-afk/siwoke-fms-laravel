<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Domain\Enums\TerminalStatus;

final class TerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MASTER_TERMINAL_MANAGE) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'site' => ['required', 'string', 'size:26'],
            'tid' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'status' => ['required', Rule::enum(TerminalStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['tid.regex' => 'TID hanya boleh berisi huruf, angka, minus, dan underscore.'];
    }
}
