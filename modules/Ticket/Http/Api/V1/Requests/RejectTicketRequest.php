<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Api\V1\Requests;

final class RejectTicketRequest extends TechnicianActionRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'reason' => ['required', 'string', 'max:1000']];
    }
}
