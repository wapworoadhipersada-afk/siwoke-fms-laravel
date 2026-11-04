<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\IAM\Application\Actions\LoginMobileUser;
use Modules\IAM\Application\Actions\LogoutMobileDevice;
use Modules\IAM\Application\Actions\RefreshMobileToken;
use Modules\IAM\Application\Actions\RevokeAllUserSessions;
use Modules\IAM\Application\DTOs\AuthSession;
use Modules\IAM\Http\Api\V1\Requests\LoginRequest;
use Modules\IAM\Http\Api\V1\Requests\RefreshRequest;
use Modules\IAM\Http\Api\V1\Resources\UserResource;

final class AuthController extends Controller
{
    /**
     * Log in from a mobile device and receive an access token and a refresh token.
     */
    public function login(LoginRequest $request, LoginMobileUser $action): JsonResponse
    {
        /** @var array{email: string, password: string, device_uid: string, platform: string, device_name?: string|null, app_version?: string|null, push_token?: string|null} $data */
        $data = $request->validated();

        return $this->session($action->handle($data));
    }

    /**
     * Exchange a refresh token for a new token pair. The old refresh token is spent.
     */
    public function refresh(RefreshRequest $request, RefreshMobileToken $action): JsonResponse
    {
        return $this->session($action->handle(
            (string) $request->validated('refresh_token'),
            (string) $request->validated('device_uid'),
        ));
    }

    /**
     * Get the signed-in user with their roles and permissions.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => UserResource::make($this->user($request))->resolve()]);
    }

    /**
     * Log out this device only.
     */
    public function logout(Request $request, LogoutMobileDevice $action): Response
    {
        $user = $this->user($request);
        $token = $user->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? $token->getAttribute('device_id') : null;

        $action->handle($user, is_numeric($deviceId) ? (int) $deviceId : null);

        return response()->noContent();
    }

    /**
     * Log out every device of the signed-in user.
     */
    public function logoutAll(Request $request, RevokeAllUserSessions $action): Response
    {
        $action->handle($this->user($request));

        return response()->noContent();
    }

    private function session(AuthSession $session): JsonResponse
    {
        return response()->json(['data' => [
            ...$session->tokens->toArray(),
            'user' => UserResource::make($session->user)->resolve(),
        ]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
