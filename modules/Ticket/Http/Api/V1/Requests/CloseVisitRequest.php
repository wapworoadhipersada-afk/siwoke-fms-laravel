<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Api\V1\Requests;

use Illuminate\Validation\Rule;
use Modules\Ticket\Domain\Enums\VisitOutcome;

final class CloseVisitRequest extends TechnicianActionRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'result' => ['required', Rule::enum(VisitOutcome::class)->only([VisitOutcome::Finish, VisitOutcome::Pending, VisitOutcome::DoneNegative])],
            'reason_code' => ['required_if:result,pending,done_negative', 'nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'data' => ['nullable', 'array'],
        ];
    }
}
