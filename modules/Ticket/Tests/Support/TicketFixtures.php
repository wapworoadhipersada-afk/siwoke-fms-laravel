<?php

declare(strict_types=1);

namespace Modules\Ticket\Tests\Support;

use App\Models\User;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Tests\Support\MasterFixtures;
use Modules\Ticket\Application\Actions\AcceptTicket;
use Modules\Ticket\Application\Actions\ArriveAtTicket;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Actions\CreateTicket;
use Modules\Ticket\Application\Actions\StartHandling;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Models\ReasonCode;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketType;

/**
 * Builds tickets through the real actions. Needs the roles and ticket catalog seeders to have run.
 */
final class TicketFixtures
{
    private static int $sequence = 0;

    public static function technician(): User
    {
        return User::factory()->create(['user_type' => UserType::Technician])->assignRole(RoleName::Technician->value);
    }

    /**
     * An office user who sees every area and may create and assign tickets.
     */
    public static function dispatcher(): User
    {
        return User::factory()->create()->assignRole(RoleName::OperationalAdmin->value)->givePermissionTo(Permissions::DATA_SCOPE_ALL);
    }

    /**
     * An office user limited to the given locations.
     *
     * @param  list<Location>  $locations
     */
    public static function scopedDispatcher(array $locations): User
    {
        $user = User::factory()->create()->assignRole(RoleName::OperationalAdmin->value);

        app(SyncUserLocationScopes::class)->handle($user, array_map(static fn (Location $location): int => $location->id, $locations));

        return $user;
    }

    public static function terminal(string $vendorCode = 'BRI', ?Location $servicePoint = null): Terminal
    {
        return MasterFixtures::terminalIn(MasterFixtures::vendor($vendorCode.'-'.++self::$sequence), $servicePoint ?? MasterFixtures::chain()['service_point']);
    }

    public static function type(string $code = 'MAINTENANCE'): TicketType
    {
        return TicketType::query()->where('code', $code)->firstOrFail();
    }

    public static function reason(ReasonType $type, string $code): ReasonCode
    {
        return ReasonCode::query()->where('type', $type)->where('code', $code)->firstOrFail();
    }

    public static function ticket(?Terminal $terminal = null, string $type = 'MAINTENANCE', ?User $creator = null, ?string $vendorTicketNo = null): Ticket
    {
        return app(CreateTicket::class)->handle($terminal ?? self::terminal(), self::type($type), $creator ?? self::dispatcher(), vendorTicketNo: $vendorTicketNo);
    }

    public static function assigned(?Ticket $ticket = null, ?User $technician = null): Ticket
    {
        return app(AssignTicket::class)->handle($ticket ?? self::ticket(), $technician ?? self::technician(), self::dispatcher());
    }

    public static function accepted(?Ticket $ticket = null, ?User $technician = null): Ticket
    {
        $technician ??= self::technician();
        $ticket = self::assigned($ticket, $technician);

        return app(AcceptTicket::class)->handle($ticket, $technician);
    }

    public static function arrived(?Ticket $ticket = null, ?User $technician = null): Ticket
    {
        $technician ??= self::technician();
        $ticket = self::accepted($ticket, $technician);

        return app(ArriveAtTicket::class)->handle($ticket, $technician);
    }

    public static function handling(?Ticket $ticket = null, ?User $technician = null): Ticket
    {
        $technician ??= self::technician();
        $ticket = self::arrived($ticket, $technician);

        return app(StartHandling::class)->handle($ticket, $technician);
    }
}
