<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IAM\Domain\Permissions;
use Modules\Ticket\Domain\Enums\TicketPriority;

final class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::TICKET_CREATE) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'vendor' => ['required', 'string', 'size:26'],
            'tid' => ['required', 'string', 'max:20'],
            'ticket_type' => ['required', 'string', 'size:26'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'scheduled_at' => ['nullable', 'date'],
            'vendor_ticket_no' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
