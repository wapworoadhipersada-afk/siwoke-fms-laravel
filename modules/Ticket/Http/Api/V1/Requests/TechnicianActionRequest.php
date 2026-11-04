<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Api\V1\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The fields every technician action may carry: when it really happened, where, and an id that
 * makes a retry after a bad connection harmless.
 */
class TechnicianActionRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['nullable', 'uuid'],
            'occurred_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
