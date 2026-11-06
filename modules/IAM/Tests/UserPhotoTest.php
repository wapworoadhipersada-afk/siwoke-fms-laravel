<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Document\Tests\Support\ImageFixtures as Img;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;

beforeEach(function () {
    Storage::fake((string) config('documents.disk'));
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->me = User::factory()->create()->assignRole(RoleName::Technician->value);
    $this->other = User::factory()->create()->assignRole(RoleName::Technician->value);
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

function photo(?string $bytes = null, string $name = 'me.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes ?? Img::jpeg(1200, 900));
}

it('lets a person set their own picture and stores it re-drawn as a small square', function () {
    $this->actingAs($this->me)
        ->post(route('iam.users.photo.update', $this->me), ['photo' => photo()])
        ->assertSessionHasNoErrors();

    $me = $this->me->fresh();
    $disk = Storage::disk((string) config('documents.disk'));

    expect($me->photo_path)->toStartWith('avatars/')->and($disk->exists($me->photo_path))->toBeTrue()
        ->and($disk->exists($me->photo_thumb_path))->toBeTrue()
        ->and(getimagesizefromstring($disk->get($me->photo_path))[0])->toBe(getimagesizefromstring($disk->get($me->photo_path))[1])
        ->and($me->photo_url)->toContain('/iam/users/'.$me->ulid.'/photo');
});

it('serves the picture to its owner and to people who manage users, and to nobody else', function () {
    $this->actingAs($this->me)->post(route('iam.users.photo.update', $this->me), ['photo' => photo()]);

    $this->get(route('iam.users.photo.show', $this->me))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->admin)->get(route('iam.users.photo.show', $this->me))->assertOk();
    $this->actingAs($this->other)->get(route('iam.users.photo.show', $this->me))->assertNotFound();
});

it('does not let a person change someone else\'s picture', function () {
    $this->actingAs($this->other)
        ->post(route('iam.users.photo.update', $this->me), ['photo' => photo()])
        ->assertForbidden();

    expect($this->me->fresh()->photo_path)->toBeNull();
});

it('lets an administrator change anyone\'s picture', function () {
    $this->actingAs($this->admin)->post(route('iam.users.photo.update', $this->me), ['photo' => photo()])->assertSessionHasNoErrors();

    expect($this->me->fresh()->photo_path)->not->toBeNull();
});

it('rejects a file that is not a picture, even when it is named like one', function () {
    $this->actingAs($this->me)
        ->post(route('iam.users.photo.update', $this->me), ['photo' => photo(Img::phpPayload(), 'shell.jpg')])
        ->assertSessionHasErrors('photo');

    expect($this->me->fresh()->photo_path)->toBeNull();
});

it('replaces the old files when a new picture is uploaded', function () {
    $this->actingAs($this->me)->post(route('iam.users.photo.update', $this->me), ['photo' => photo()]);
    $first = $this->me->fresh();

    $this->post(route('iam.users.photo.update', $this->me), ['photo' => photo(Img::png(600, 600), 'me.png')]);

    $disk = Storage::disk((string) config('documents.disk'));

    expect($disk->exists($first->photo_path))->toBeFalse()
        ->and($disk->exists($first->photo_thumb_path))->toBeFalse()
        ->and($disk->exists($this->me->fresh()->photo_path))->toBeTrue();
});

it('removes the picture and its files', function () {
    $this->actingAs($this->me)->post(route('iam.users.photo.update', $this->me), ['photo' => photo()]);
    $before = $this->me->fresh();

    $this->delete(route('iam.users.photo.destroy', $this->me))->assertSessionHasNoErrors();

    expect($this->me->fresh()->photo_path)->toBeNull()
        ->and(Storage::disk((string) config('documents.disk'))->exists($before->photo_path))->toBeFalse();
});

it('never exposes the storage path in what is sent to the browser', function () {
    $this->actingAs($this->me)->post(route('iam.users.photo.update', $this->me), ['photo' => photo()]);

    expect($this->me->fresh()->toArray())->not->toHaveKeys(['photo_path', 'photo_thumb_path'])->toHaveKey('photo_url');
});

it('shows the own profile page to everyone signed in', function () {
    $this->actingAs($this->me)->get(route('me.show'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('master/me/Show')->where('me.ulid', $this->me->ulid));
});
