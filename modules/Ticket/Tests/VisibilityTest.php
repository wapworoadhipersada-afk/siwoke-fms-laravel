<?php

declare(strict_types=1);

use App\Models\User;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Tests\Support\MasterFixtures;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);

    $this->west = MasterFixtures::chain();
    $this->east = MasterFixtures::chain();
    $this->westTicket = Fx::ticket(Fx::terminal('W', $this->west['area']));
    $this->eastTicket = Fx::ticket(Fx::terminal('E', $this->east['area']));
});

function ticketIds(object $query): array
{
    $ids = $query->pluck('id')->all();
    sort($ids);

    return $ids;
}

it('shows everything to users who see every area', function (RoleName $role) {
    $user = User::factory()->create()->assignRole($role->value);

    expect(ticketIds(Ticket::query()->visibleTo($user)))->toBe(ticketIds(Ticket::query()));
})->with([RoleName::SuperAdmin, RoleName::Managerial]);

it('shows an office user only the tickets at sites in their area', function () {
    $coordinator = Fx::scopedDispatcher([$this->west['service_point']]);

    expect(ticketIds(Ticket::query()->visibleTo($coordinator)))->toBe([$this->westTicket->id]);
});

it('shows an office user with no area nothing', function () {
    $nobody = User::factory()->create()->assignRole(RoleName::Coordinator->value);

    expect(Ticket::query()->visibleTo($nobody)->count())->toBe(0);
});

it('shows a technician only the tickets they are or were assigned to, even inside their own area', function () {
    $tech = Fx::technician();
    $sameAreaTicket = Fx::ticket(Fx::terminal('W2', $this->west['area']));

    app(SyncUserLocationScopes::class)->handle($tech, [$this->west['province']->id]);
    app(AssignTicket::class)->handle($this->westTicket, $tech, Fx::dispatcher());

    expect(ticketIds(Ticket::query()->visibleTo($tech)))->toBe([$this->westTicket->id])
        ->and(ticketIds(Ticket::query()->visibleTo($tech)))->not->toContain($sameAreaTicket->id);
});

it('stops showing a technician a job that was taken away before they started', function () {
    $tech = Fx::technician();
    $office = Fx::dispatcher();
    app(AssignTicket::class)->handle($this->westTicket, $tech, $office);
    app(AssignTicket::class)->handle($this->westTicket->fresh(), Fx::technician(), $office);

    expect(ticketIds(Ticket::query()->visibleTo($tech)))->toBe([]);
});

it('keeps showing a technician a ticket they actually visited, even after it goes to someone else', function () {
    $tech = Fx::technician();
    $ticket = Fx::handling($this->westTicket, $tech);
    app(CloseVisit::class)->handle(
        $ticket, $tech, VisitOutcome::Pending,
        reason: Fx::reason(ReasonType::Pending, 'NETWORK_ISSUE'),
    );
    app(AssignTicket::class)->handle($ticket->fresh(), Fx::technician(), Fx::dispatcher());

    expect(ticketIds(Ticket::query()->visibleTo($tech)))->toBe([$this->westTicket->id]);
});

it('shows a technician nothing before they are given anything', function () {
    expect(Ticket::query()->visibleTo(Fx::technician())->count())->toBe(0);
});
