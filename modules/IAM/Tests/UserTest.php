<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Enums\UserType;

it('generates a public ulid and applies defaults on creation', function () {
    $user = User::factory()->create();

    expect($user->ulid)->toHaveLength(26)
        ->and($user->user_type)->toBe(UserType::Staff)
        ->and($user->is_active)->toBeTrue();
});

it('frees the email of a soft-deleted user for reuse', function () {
    $old = User::factory()->create(['email' => 'budi@example.com']);
    $old->delete();

    $new = User::factory()->create(['email' => 'budi@example.com']);

    expect($new->exists)->toBeTrue()
        ->and(User::withTrashed()->where('email', 'budi@example.com')->count())->toBe(2);
});

it('rejects two live users with the same email regardless of case', function () {
    User::factory()->create(['email' => 'budi@example.com']);

    User::factory()->create(['email' => 'BUDI@example.com']);
})->throws(UniqueConstraintViolationException::class);

it('rejects two live users with the same employee number', function () {
    User::factory()->create(['employee_no' => 'EMP-001']);

    User::factory()->create(['employee_no' => 'EMP-001']);
})->throws(UniqueConstraintViolationException::class);

it('allows many users without an employee number', function () {
    User::factory()->count(2)->create(['employee_no' => null]);

    expect(User::query()->whereNull('employee_no')->count())->toBe(2);
});

it('rejects an unknown user type at the database level', function () {
    $user = User::factory()->create();

    DB::table('users')->where('id', $user->id)->update(['user_type' => 'hacker']);
})->throws(QueryException::class);

it('audits changes to tracked user attributes', function () {
    $user = User::factory()->create(['name' => 'Budi']);

    $user->update(['name' => 'Budi Santoso']);

    $activity = $user->activitiesAsSubject()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe('iam')
        ->and($activity->event)->toBe('updated')
        ->and($activity->attribute_changes->toArray())->toMatchArray(['attributes' => ['name' => 'Budi Santoso']]);
});

it('never writes the password into the audit log', function () {
    $user = User::factory()->create();

    $user->update(['password' => 'a-brand-new-password']);

    expect($user->activitiesAsSubject()->where('event', 'updated')->count())->toBe(0);
});
