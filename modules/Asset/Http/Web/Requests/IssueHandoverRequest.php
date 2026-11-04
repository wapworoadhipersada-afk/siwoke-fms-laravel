<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\IAM\Domain\Permissions;

final class IssueHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::ASSET_TRANSFER) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'warehouse' => ['required', 'string', 'size:26'],
            'technician' => ['required', 'string', 'size:26'],
            'assets' => ['nullable', 'array', 'max:500'],
            'assets.*' => ['string', 'size:26'],
            'stock' => ['nullable', 'array', 'max:100'],
            'stock.*.model' => ['required_with:stock.*.quantity', 'string', 'size:26'],
            'stock.*.vendor' => ['required_with:stock.*.quantity', 'string', 'size:26'],
            'stock.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
