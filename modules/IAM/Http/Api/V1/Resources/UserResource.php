<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Api\V1\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property User $resource
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'employee_no' => $user->employee_no,
            'user_type' => $user->user_type->value,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
