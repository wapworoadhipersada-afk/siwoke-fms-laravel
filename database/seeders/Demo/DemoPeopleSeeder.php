<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\IAM\Application\Actions\SaveUser;
use Modules\IAM\Application\Actions\SaveUserPhoto;
use Modules\IAM\Domain\Enums\EmploymentStatus;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Application\Actions\SyncUserVendors;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Vendor;

/**
 * Office staff, coordinators, leaders and technicians for both vendors. Everyone signs in with the password
 * "password". Who sits under whom is not stored anywhere: it follows from the areas and vendor each person holds.
 */
final class DemoPeopleSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private int $technicians = 0;

    private int $serial = 0;

    public function run(SaveUser $users, SaveUserPhoto $photos, SyncUserLocationScopes $scopes, SyncUserVendors $vendorScopes): void
    {
        DemoSupport::seed();

        $actor = User::query()->role(RoleName::SuperAdmin->value)->orderBy('id')->first()
            ?? User::factory()->create(['name' => 'Admin Demo', 'email' => 'demo-admin@demo.fms.test'])->assignRole(RoleName::SuperAdmin->value);

        $vendors = Vendor::query()->whereIn('code', ['BRI', 'CIMB'])->get()->keyBy('code');
        $provinces = Location::query()->where('type', 'province')->pluck('id', 'code');
        $servicePoints = Location::query()->where('type', 'service_point')->pluck('id', 'code');
        $areasOf = Location::query()->where('type', 'area')->get()->groupBy('parent_id');

        $make = function (string $role, string $name, array $locationIds, ?Vendor $vendor, ?string $code = null) use ($users, $photos, $scopes, $vendorScopes, $actor): User {
            $technician = $role === RoleName::Technician->value;
            $user = $users->handle(null, $this->data($name, $this->slug($name), RoleName::from($role), $technician ? UserType::Technician : UserType::Staff, $code), $actor)['user'];

            $photos->handle($user, DemoSupport::upload(DemoSupport::avatar($name), 'avatar.jpg'), $actor);
            $scopes->handle($user, $locationIds);
            $vendorScopes->handle($user, $vendor === null ? [] : [$vendor->id], $actor);

            return $user;
        };

        // Management and back office.
        $make(RoleName::Managerial->value, 'Hartono Wijaya', $provinces->values()->all(), null);
        $make(RoleName::OperationalAdmin->value, 'Melati Kusuma', $provinces->values()->all(), $vendors['BRI']);
        $make(RoleName::OperationalAdmin->value, 'Rangga Pratama', $provinces->values()->all(), $vendors['CIMB']);

        // Coordinators hold whole provinces for one vendor; leaders hold service points; technicians hold areas.
        $coordinators = [
            ['BRI', 'Koordinator Jabodetabek BRI', ['JKT', 'JBR', 'BTN']],
            ['BRI', 'Koordinator Jateng Jatim BRI', ['JTG', 'JTM']],
            ['CIMB', 'Koordinator Jabodetabek CIMB', ['JKT', 'JBR', 'BTN']],
            ['CIMB', 'Koordinator Jateng Jatim CIMB', ['JTG', 'JTM']],
        ];

        foreach ($coordinators as [$vendorCode, $title, $provinceCodes]) {
            $make(RoleName::Coordinator->value, $this->personal($title), array_map(fn (string $code): int => $provinces[$code], $provinceCodes), $vendors[$vendorCode]);
        }

        $leaders = [
            ['BRI', ['SP-JKT-PST', 'SP-JKT-SEL']], ['BRI', ['SP-JBR-BKS', 'SP-JBR-BDG']], ['BRI', ['SP-BTN-TNG']], ['BRI', ['SP-JTG-SMG']], ['BRI', ['SP-JTM-SBY']],
            ['CIMB', ['SP-JKT-BRT', 'SP-JKT-UTR']], ['CIMB', ['SP-JBR-DPK', 'SP-JBR-BGR']], ['CIMB', ['SP-BTN-TSL']], ['CIMB', ['SP-JTG-SLO']], ['CIMB', ['SP-JTM-MLG']],
        ];

        foreach ($leaders as [$vendorCode, $spCodes]) {
            $make(RoleName::Leader->value, DemoSupport::personName(), array_map(fn (string $code): int => $servicePoints[$code], $spCodes), $vendors[$vendorCode], $this->nextCode('LDR'));
        }

        // Two technicians per service point, each covering two of its three areas (so they overlap, as in real life).
        foreach (Location::query()->where('type', 'service_point')->orderBy('id')->get() as $servicePoint) {
            $areas = $areasOf[$servicePoint->id]->values();

            foreach ([[0, 1], [1, 2]] as [$from, $to]) {
                $make(RoleName::Technician->value, DemoSupport::personName(), [$areas[$from]->id, $areas[$to]->id], $servicePoint->vendor_id === $vendors['BRI']->id ? $vendors['BRI'] : $vendors['CIMB'], $this->nextCode('ENG'));
                $this->technicians++;
            }
        }

        $this->command?->info("{$this->technicians} teknisi, 10 leader, 4 koordinator, 3 staf kantor (password: ".self::PASSWORD.').');
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $name, string $username, RoleName $role, UserType $type, ?string $code = null): array
    {
        $field = in_array($role, [RoleName::Technician, RoleName::Leader], true);
        $this->serial++;

        return [
            'name' => $name,
            'email' => $username.'@demo.fms.test',
            'phone' => DemoSupport::phone(),
            'employee_no' => null,
            'user_code' => $code,
            'username' => $field ? $username : null,
            'employment_status' => $field ? DemoSupport::pick(array_column(EmploymentStatus::options(), 'value')) : null,
            'nik' => $field ? '32'.DemoSupport::digits(14) : null,
            'address' => DemoSupport::address('Indonesia'),
            'password' => self::PASSWORD,
            'user_type' => $type->value,
            'is_active' => true,
            'roles' => [$role->value],
        ];
    }

    private function nextCode(string $prefix): string
    {
        return sprintf('%s-%04d', $prefix, $this->serial + 1);
    }

    private function slug(string $name): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '.', $name) ?? 'user').'.'.($this->serial + 1);
    }

    private function personal(string $title): string
    {
        return DemoSupport::personName().' ('.str_replace('Koordinator ', 'Koord. ', $title).')';
    }
}
