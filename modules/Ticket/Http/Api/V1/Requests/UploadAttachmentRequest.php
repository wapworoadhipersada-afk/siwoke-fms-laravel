<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Api\V1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Document\Domain\Enums\AttachmentKind;

final class UploadAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Only that it is a file is checked here; whether it is a real, safe image is judged from its content.
            'file' => ['required', 'file', 'max:'.(int) config('documents.max_upload_kilobytes')],
            'kind' => ['required', Rule::enum(AttachmentKind::class)->only(AttachmentKind::uploadable())],
            'client_uuid' => ['nullable', 'uuid'],
            'captured_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }
}
