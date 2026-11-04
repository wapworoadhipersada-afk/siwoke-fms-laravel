<?php

declare(strict_types=1);

namespace Modules\IAM\Application\DTOs;

use App\Models\User;

final readonly class AuthSession
{
    public function __construct(
        public User $user,
        public TokenPair $tokens,
    ) {}
}
