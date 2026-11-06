<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Document\Tests\Support\ImageFixtures as Img;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Tests\Support\MasterFixtures as Fx;

beforeEach(function () {
    Storage::fake((string) config('documents.disk'));
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

function technicianData(array $over = []): array
{
    return array_merge([
        'user_code' => 'eng-0012',
        'name' => 'Rudi Teknisi',
        'username' => 'rudi.t',
        'email' => 'rudi@contoh.test',
        'employment_status' => 'pkwt',
        'nik' => '3201010101010001',
        'phone' => '0812-3456-7890',
        'address' => 'Jl. Contoh No. 1, Bekasi',
        'password' => 'Sup3r-Rahasia-2026',
        'password_confirmation' => 'Sup3r-Rahasia-2026',
        'photo' => UploadedFile::fake()->createWithContent('rudi.jpg', Img::jpeg(800, 600)),
        'role' => 'technician',
        'is_active' => 1,
    ], $over);
}

describe('registering a technician', function () {
    it('keeps every detail, the chosen password and the picture, then goes straight to vendors and areas', function () {
        $response = $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData());

        $user = User::query()->where('email', 'rudi@contoh.test')->firstOrFail();
        $response->assertRedirect(route('master.user-scopes.edit', $user->ulid));

        expect($user->user_code)->toBe('ENG-0012')
            ->and($user->username)->toBe('rudi.t')
            ->and($user->employment_status->value)->toBe('pkwt')
            ->and($user->nik)->toBe('3201010101010001')
            ->and($user->phone)->toBe('0812-3456-7890')
            ->and($user->address)->toBe('Jl. Contoh No. 1, Bekasi')
            ->and(Hash::check('Sup3r-Rahasia-2026', $user->password))->toBeTrue()
            ->and($user->hasRole('technician'))->toBeTrue()
            ->and($user->user_type->value)->toBe('technician')
            ->and($user->photo_path)->not->toBeNull()
            ->and(Storage::disk((string) config('documents.disk'))->exists($user->photo_path))->toBeTrue();
    });

    it('accepts each employment status and no other', function () {
        foreach (['freelance', 'pkwt', 'tetap', 'kontrak'] as $index => $status) {
            $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData([
                'user_code' => "T-{$index}", 'username' => "user{$index}", 'email' => "u{$index}@contoh.test", 'nik' => '320101010101000'.$index, 'employment_status' => $status,
                'photo' => UploadedFile::fake()->createWithContent('a.jpg', Img::jpeg(400, 400)),
            ]))->assertSessionHasNoErrors();
        }

        $this->post(route('iam.engineers.store'), technicianData(['employment_status' => 'magang']))->assertSessionHasErrors('employment_status');
    });

    it('requires every field', function (string $field) {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData([$field => '']))->assertSessionHasErrors($field);

        expect(User::query()->where('email', 'rudi@contoh.test')->exists())->toBeFalse();
    })->with(['user_code', 'name', 'username', 'email', 'employment_status', 'nik', 'phone', 'address', 'password']);

    it('requires a picture', function () {
        $data = technicianData();
        unset($data['photo']);

        $this->actingAs($this->admin)->post(route('iam.engineers.store'), $data)->assertSessionHasErrors('photo');
    });

    it('refuses a weak or unconfirmed password', function () {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData(['password' => 'abc', 'password_confirmation' => 'abc']))->assertSessionHasErrors('password');
        $this->post(route('iam.engineers.store'), technicianData(['password_confirmation' => 'beda']))->assertSessionHasErrors('password');
    });

    it('refuses a NIK that is not 16 digits, and a username with odd characters', function () {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData(['nik' => '12345']))->assertSessionHasErrors('nik');
        $this->post(route('iam.engineers.store'), technicianData(['nik' => '32010101010100AB']))->assertSessionHasErrors('nik');
        $this->post(route('iam.engineers.store'), technicianData(['username' => 'Rudi T!']))->assertSessionHasErrors('username');
    });

    it('refuses a code, username, email or NIK that someone already has', function (string $field, string $value) {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData())->assertSessionHasNoErrors();

        $other = technicianData(['user_code' => 'OTHER-1', 'username' => 'other', 'email' => 'other@contoh.test', 'nik' => '3201010101010002', $field => $value]);
        $this->post(route('iam.engineers.store'), $other)->assertSessionHasErrors($field);
    })->with([['user_code', 'ENG-0012'], ['username', 'RUDI.T'], ['email', 'RUDI@contoh.test'], ['nik', '3201010101010001']]);

    it('leaves no account behind when the picture cannot be used', function () {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData(['photo' => UploadedFile::fake()->createWithContent('rudi.jpg', Img::phpPayload())]))->assertSessionHasErrors('photo');

        expect(User::query()->where('email', 'rudi@contoh.test')->exists())->toBeFalse();
    });

    it('never sends the NIK or address to the browser inside the signed-in user', function () {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData());
        $user = User::query()->where('email', 'rudi@contoh.test')->firstOrFail();

        expect($user->toArray())->not->toHaveKeys(['nik', 'address', 'password']);
    });
});

describe('changing a technician', function () {
    beforeEach(function () {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData());
        $this->tech = User::query()->where('email', 'rudi@contoh.test')->firstOrFail();
    });

    it('keeps the password and the picture when they are left alone', function () {
        $data = technicianData(['name' => 'Rudi Baru', 'password' => '', 'password_confirmation' => '']);
        unset($data['photo']);

        $this->put(route('iam.engineers.update', $this->tech), $data)->assertSessionHasNoErrors()->assertRedirect(route('iam.engineers.edit', $this->tech));

        $fresh = $this->tech->fresh();
        expect($fresh->name)->toBe('Rudi Baru')->and(Hash::check('Sup3r-Rahasia-2026', $fresh->password))->toBeTrue()->and($fresh->photo_path)->toBe($this->tech->photo_path);
    });

    it('sets a new password and ends the sessions the person had', function () {
        $this->tech->createToken('phone');
        $data = technicianData(['password' => 'Lain-Lagi-Aman-77', 'password_confirmation' => 'Lain-Lagi-Aman-77']);
        unset($data['photo']);

        $this->put(route('iam.engineers.update', $this->tech), $data)->assertSessionHasNoErrors();

        expect(Hash::check('Lain-Lagi-Aman-77', $this->tech->fresh()->password))->toBeTrue()->and($this->tech->tokens()->count())->toBe(0);
    });

    it('lets the same person keep their own code, username, email and NIK', function () {
        $data = technicianData(['password' => '', 'password_confirmation' => '']);
        unset($data['photo']);

        $this->put(route('iam.engineers.update', $this->tech), $data)->assertSessionHasNoErrors();
    });
});

describe('vendors and areas of a technician', function () {
    beforeEach(function () {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData());
        $this->tech = User::query()->where('email', 'rudi@contoh.test')->firstOrFail();
        $this->bri = Fx::vendor('BRI');
        $this->chain = Fx::chain($this->bri);
    });

    it('shows the screen with the person, the places as a tree and the vendors', function () {
        $this->get(route('master.user-scopes.edit', $this->tech->ulid))->assertOk()
            ->assertInertia(fn ($page) => $page->component('master/user-scopes/Edit')
                ->where('managed.user_code', 'ENG-0012')
                ->has('managed.photo_url')
                ->has('locations', 3)
                ->where('locations.0.parent_id', null)
                ->where('vendors.0.code', 'BRI'));
    });

    it('saves several vendors and areas at once and stays on the screen', function () {
        $this->put(route('master.user-scopes.update', $this->tech->ulid), ['vendor_ids' => [$this->bri->ulid], 'location_ids' => [$this->chain['service_point']->id]])
            ->assertRedirect(route('master.user-scopes.edit', $this->tech->ulid));

        expect(app(VendorAccess::class)->allowedIds($this->tech->fresh()))->toBe([$this->bri->id])
            ->and(DB::table('user_location_scopes')->where('user_id', $this->tech->id)->pluck('location_id')->all())->toBe([$this->chain['service_point']->id]);
    });

    it('goes back to the technician list on "Simpan dan selesai"', function () {
        $this->put(route('master.user-scopes.update', $this->tech->ulid), ['done' => 1, 'location_ids' => [$this->chain['area']->id]])
            ->assertRedirect(route('iam.engineers.index'));
    });

    it('is closed to people who may not change users', function () {
        $viewer = User::factory()->create()->assignRole(RoleName::Technician->value);

        $this->actingAs($viewer)->get(route('master.user-scopes.edit', $this->tech->ulid))->assertForbidden();
        $this->put(route('master.user-scopes.update', $this->tech->ulid), ['location_ids' => []])->assertForbidden();
    });
});

describe('who may use the technician form', function () {
    it('registers a leader as an office-screen account', function () {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData(['role' => 'leader']))->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'rudi@contoh.test')->firstOrFail();
        expect($user->hasRole('leader'))->toBeTrue()->and($user->user_type->value)->toBe('staff');
    });

    it('only accepts the leader or technician position', function () {
        $this->actingAs($this->admin)->post(route('iam.engineers.store'), technicianData(['role' => 'super-admin']))->assertSessionHasErrors('role');
        $this->post(route('iam.engineers.store'), technicianData(['role' => 'coordinator']))->assertSessionHasErrors('role');
    });

    it('sends office accounts to their own form and keeps field roles out of it', function () {
        $office = User::factory()->create()->assignRole(RoleName::Coordinator->value);

        $this->actingAs($this->admin)->get(route('iam.engineers.edit', $office))->assertRedirect(route('iam.users.edit', $office));
        $this->put(route('iam.engineers.update', $office), technicianData(['email' => 'x@contoh.test']))->assertNotFound();
        $this->post(route('iam.users.store'), ['name' => 'Salah', 'email' => 'salah@contoh.test', 'user_type' => 'staff', 'roles' => ['technician']])->assertSessionHasErrors('roles');
    });

    it('is closed to people without the permission', function () {
        $user = User::factory()->create()->assignRole(RoleName::Technician->value);

        $this->actingAs($user)->get(route('iam.engineers.create'))->assertForbidden();
        $this->post(route('iam.engineers.store'), technicianData())->assertForbidden();
    });
});
