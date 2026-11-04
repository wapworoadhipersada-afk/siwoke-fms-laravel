<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

it('audits a successful dashboard login and stamps the last login', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    $activity = Activity::query()->where('event', 'web_login')->sole();

    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->properties['ip'])->not->toBeNull()
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('audits a failed dashboard login without recording the password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'not-the-password']);

    $activity = Activity::query()->where('event', 'web_login_failed')->sole();

    expect($activity->properties['email'])->toBe(strtolower($user->email))
        ->and(json_encode($activity->properties))->not->toContain('not-the-password');
});

it('audits a dashboard logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'));

    expect(Activity::query()->where('event', 'web_logout')->count())->toBe(1);
});

it('does not expose public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertNotFound();
});
