<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\Models\Device;
use Modules\IAM\Domain\Models\RefreshToken;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

const MOBILE_AUTH = '/api/mobile/v1/auth';
const MOBILE_DEVICE_A = 'device-aaaa-0001';
const MOBILE_DEVICE_B = 'device-bbbb-0002';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function mobileTechnician(array $attributes = []): User
{
    return User::factory()->create([
        'user_type' => UserType::Technician,
        'email' => 'tech@example.com',
        ...$attributes,
    ])->assignRole(RoleName::Technician->value);
}

function mobilePayload(array $overrides = []): array
{
    return [
        'email' => 'tech@example.com',
        'password' => 'password',
        'device_uid' => MOBILE_DEVICE_A,
        'platform' => 'android',
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function mobileLogin(TestCase $test, array $overrides = []): array
{
    return $test->postJson(MOBILE_AUTH.'/login', mobilePayload($overrides))->assertOk()->json('data');
}

function mobileRefresh(TestCase $test, string $refreshToken, string $deviceUid = MOBILE_DEVICE_A): TestResponse
{
    return $test->postJson(MOBILE_AUTH.'/refresh', ['refresh_token' => $refreshToken, 'device_uid' => $deviceUid]);
}

function mobileMe(TestCase $test, ?string $accessToken): TestResponse
{
    // The guard caches the resolved user for the lifetime of the app, which a real request never shares.
    app('auth')->forgetGuards();
    $test->flushHeaders();

    return $accessToken === null
        ? $test->getJson(MOBILE_AUTH.'/me')
        : $test->withToken($accessToken)->getJson(MOBILE_AUTH.'/me');
}

describe('login', function () {
    it('returns a token pair and the user profile', function () {
        mobileTechnician();

        $this->postJson(MOBILE_AUTH.'/login', mobilePayload())
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'token_type', 'access_token', 'access_expires_at', 'refresh_token', 'refresh_expires_at',
                'user' => ['id', 'name', 'email', 'user_type', 'roles', 'permissions'],
            ]])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.roles', ['technician']);
    });

    it('registers the device and stamps the last login', function () {
        $user = mobileTechnician();

        mobileLogin($this, ['device_name' => 'Redmi Note 12', 'app_version' => '1.0.3']);

        $device = Device::query()->where('user_id', $user->id)->sole();

        expect($device->device_uid)->toBe(MOBILE_DEVICE_A)
            ->and($device->device_name)->toBe('Redmi Note 12')
            ->and($device->app_version)->toBe('1.0.3')
            ->and($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('stores only a hash of the refresh token', function () {
        mobileTechnician();

        $plain = mobileLogin($this)['refresh_token'];

        expect(RefreshToken::query()->where('token_hash', $plain)->exists())->toBeFalse()
            ->and(RefreshToken::query()->where('token_hash', hash('sha256', $plain))->exists())->toBeTrue();
    });

    it('matches the email case-insensitively', function () {
        mobileTechnician();

        $this->postJson(MOBILE_AUTH.'/login', mobilePayload(['email' => 'TECH@Example.com']))->assertOk();
    });

    it('rejects a wrong password with a problem response and audits it', function () {
        mobileTechnician();

        $this->postJson(MOBILE_AUTH.'/login', mobilePayload(['password' => 'wrong-password']))
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'invalid_credentials');

        expect(Activity::query()->where('event', 'login_failed')->count())->toBe(1);
    });

    it('answers an unknown email exactly like a wrong password', function () {
        $this->postJson(MOBILE_AUTH.'/login', mobilePayload(['email' => 'nobody@example.com']))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    });

    it('refuses a user without the mobile permission', function () {
        User::factory()->create(['email' => 'tech@example.com']);

        $this->postJson(MOBILE_AUTH.'/login', mobilePayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'account_not_permitted');
    });

    it('refuses an inactive user', function () {
        mobileTechnician(['is_active' => false]);

        $this->postJson(MOBILE_AUTH.'/login', mobilePayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'account_not_permitted');
    });

    it('refuses a blocked device', function () {
        mobileTechnician();
        mobileLogin($this);
        Device::query()->update(['blocked_at' => now()]);

        $this->postJson(MOBILE_AUTH.'/login', mobilePayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'device_blocked');
    });

    it('validates the payload', function () {
        $this->postJson(MOBILE_AUTH.'/login', ['email' => 'tech@example.com', 'password' => 'x', 'platform' => 'windows'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_uid', 'platform']);
    });

    it('replaces the previous session when the same device logs in again', function () {
        mobileTechnician();
        $first = mobileLogin($this);
        $second = mobileLogin($this);

        mobileMe($this, $first['access_token'])->assertUnauthorized();
        mobileMe($this, $second['access_token'])->assertOk();
        mobileRefresh($this, $first['refresh_token'])->assertUnauthorized();
    });

    it('is throttled after five attempts for the same email', function () {
        RateLimiter::clear('mobile-login');
        mobileTechnician();

        foreach (range(1, 5) as $ignored) {
            $this->postJson(MOBILE_AUTH.'/login', mobilePayload(['password' => 'wrong-password']))->assertUnauthorized();
        }

        $this->postJson(MOBILE_AUTH.'/login', mobilePayload())->assertTooManyRequests()->assertHeader('Retry-After');
    });
});

describe('access token', function () {
    it('is required for the profile endpoint', function () {
        mobileMe($this, null)->assertUnauthorized();
    });

    it('returns the profile with roles and permissions', function () {
        mobileTechnician();
        $session = mobileLogin($this);

        mobileMe($this, $session['access_token'])
            ->assertOk()
            ->assertJsonPath('data.email', 'tech@example.com')
            ->assertJsonPath('data.roles', ['technician'])
            ->assertJsonFragment(['permissions' => ['asset.transfer.receive', 'asset.view', 'document.view', 'mobile.access', 'ticket.view', 'ticket.work']]);
    });

    it('expires after its lifetime', function () {
        mobileTechnician();
        $session = mobileLogin($this);

        $this->travel((int) config('iam.access_token_ttl_minutes') + 1)->minutes();

        mobileMe($this, $session['access_token'])->assertUnauthorized();
    });

    it('stops working the moment the account is disabled', function () {
        $user = mobileTechnician();
        $session = mobileLogin($this);
        mobileMe($this, $session['access_token'])->assertOk();

        $user->update(['is_active' => false]);

        mobileMe($this, $session['access_token'])->assertForbidden()->assertJsonPath('code', 'account_not_permitted');
    });

    it('stops working the moment the device is blocked', function () {
        mobileTechnician();
        $session = mobileLogin($this);

        Device::query()->update(['blocked_at' => now()]);

        mobileMe($this, $session['access_token'])->assertForbidden()->assertJsonPath('code', 'device_blocked');
    });
});

describe('refresh', function () {
    it('rotates the pair and kills the old access token', function () {
        mobileTechnician();
        $first = mobileLogin($this);

        $second = mobileRefresh($this, $first['refresh_token'])->assertOk()->json('data');

        expect($second['refresh_token'])->not->toBe($first['refresh_token'])
            ->and($second['access_token'])->not->toBe($first['access_token']);

        mobileMe($this, $first['access_token'])->assertUnauthorized();
        mobileMe($this, $second['access_token'])->assertOk();
    });

    it('chains the new token to the same family', function () {
        mobileTechnician();
        $first = mobileLogin($this);
        mobileRefresh($this, $first['refresh_token'])->assertOk();

        $tokens = RefreshToken::query()->orderBy('id')->get();

        expect($tokens)->toHaveCount(2)
            ->and($tokens[0]->family)->toBe($tokens[1]->family)
            ->and($tokens[0]->replaced_by_id)->toBe($tokens[1]->id)
            ->and($tokens[0]->used_at)->not->toBeNull();
    });

    it('revokes the whole device when a spent token is replayed', function () {
        mobileTechnician();
        $first = mobileLogin($this);
        $second = mobileRefresh($this, $first['refresh_token'])->assertOk()->json('data');

        mobileRefresh($this, $first['refresh_token'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'token_reuse_detected');

        mobileMe($this, $second['access_token'])->assertUnauthorized();
        mobileRefresh($this, $second['refresh_token'])->assertUnauthorized()->assertJsonPath('code', 'invalid_refresh_token');
        expect(Activity::query()->where('event', 'refresh_token_reuse_detected')->count())->toBe(1);
    });

    it('rejects a token presented from another device and revokes the owner device', function () {
        mobileTechnician();
        $session = mobileLogin($this);

        mobileRefresh($this, $session['refresh_token'], MOBILE_DEVICE_B)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_refresh_token');

        mobileRefresh($this, $session['refresh_token'])->assertUnauthorized();
        expect(Activity::query()->where('event', 'refresh_device_mismatch')->count())->toBe(1);
    });

    it('rejects an expired refresh token', function () {
        mobileTechnician();
        $session = mobileLogin($this);

        $this->travel((int) config('iam.refresh_token_ttl_days') + 1)->days();

        mobileRefresh($this, $session['refresh_token'])->assertUnauthorized()->assertJsonPath('code', 'invalid_refresh_token');
    });

    it('rejects an unknown token', function () {
        mobileRefresh($this, str_repeat('a', 64))->assertUnauthorized()->assertJsonPath('code', 'invalid_refresh_token');
    });

    it('refuses to refresh for a user disabled since login', function () {
        $user = mobileTechnician();
        $session = mobileLogin($this);
        $user->update(['is_active' => false]);

        mobileRefresh($this, $session['refresh_token'])->assertForbidden()->assertJsonPath('code', 'account_not_permitted');
        mobileMe($this, $session['access_token'])->assertUnauthorized();
    });

    it('validates the payload', function () {
        $this->postJson(MOBILE_AUTH.'/refresh', ['refresh_token' => 'too-short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refresh_token', 'device_uid']);
    });
});

describe('logout', function () {
    it('ends this device session including its refresh token', function () {
        mobileTechnician();
        $session = mobileLogin($this);

        $this->flushHeaders();
        $this->withToken($session['access_token'])->postJson(MOBILE_AUTH.'/logout')->assertNoContent();

        mobileMe($this, $session['access_token'])->assertUnauthorized();
        mobileRefresh($this, $session['refresh_token'])->assertUnauthorized();
        expect(Activity::query()->where('event', 'logout')->count())->toBe(1);
    });

    it('leaves other devices of the same user signed in', function () {
        mobileTechnician();
        $phone = mobileLogin($this);
        $tablet = mobileLogin($this, ['device_uid' => MOBILE_DEVICE_B]);

        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->withToken($phone['access_token'])->postJson(MOBILE_AUTH.'/logout')->assertNoContent();

        mobileMe($this, $phone['access_token'])->assertUnauthorized();
        mobileMe($this, $tablet['access_token'])->assertOk();
    });

    it('ends every device on logout-all', function () {
        mobileTechnician();
        $phone = mobileLogin($this);
        $tablet = mobileLogin($this, ['device_uid' => MOBILE_DEVICE_B]);

        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->withToken($phone['access_token'])->postJson(MOBILE_AUTH.'/logout-all')->assertNoContent();

        mobileMe($this, $phone['access_token'])->assertUnauthorized();
        mobileMe($this, $tablet['access_token'])->assertUnauthorized();
        mobileRefresh($this, $tablet['refresh_token'], MOBILE_DEVICE_B)->assertUnauthorized();
    });

    it('requires authentication', function () {
        $this->postJson(MOBILE_AUTH.'/logout')->assertUnauthorized();
    });
});
