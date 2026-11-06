<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Application\Actions\SyncUserVendors;
use Modules\Master\Tests\Support\MasterFixtures as MFx;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Services\TechnicianCoverage;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);

    $this->chain = MFx::chain();
    $this->terminal = Fx::terminal('BRI', $this->chain['area']);
    $this->ticket = Fx::ticket($this->terminal);
    $this->tech = Fx::technician();
    $this->coverage = app(TechnicianCoverage::class);
});

it('covers a ticket when the engineer has no vendors and no areas assigned', function () {
    expect($this->coverage->covers($this->tech, $this->ticket))->toBeTrue()
        ->and($this->coverage->reasonNot($this->tech, $this->ticket))->toBeNull();
});

it('covers a ticket whose vendor and area are both his', function () {
    app(SyncUserVendors::class)->handle($this->tech, [$this->ticket->vendor_id]);
    app(SyncUserLocationScopes::class)->handle($this->tech, [$this->chain['service_point']->id]);

    expect($this->coverage->covers($this->tech, $this->ticket->fresh()))->toBeTrue();
});

it('does not cover a ticket of another vendor', function () {
    app(SyncUserVendors::class)->handle($this->tech, [MFx::vendor('OTHER')->id]);

    expect($this->coverage->covers($this->tech, $this->ticket))->toBeFalse()
        ->and($this->coverage->reasonNot($this->tech, $this->ticket))->toContain('vendor');
});

it('does not cover a ticket outside his areas', function () {
    app(SyncUserLocationScopes::class)->handle($this->tech, [MFx::chain()['area']->id]);

    expect($this->coverage->covers($this->tech, $this->ticket))->toBeFalse()
        ->and($this->coverage->reasonNot($this->tech, $this->ticket))->toContain('area');
});

it('refuses to assign a ticket to an engineer who does not cover it, and says why on the technician field', function () {
    app(SyncUserLocationScopes::class)->handle($this->tech, [MFx::chain()['area']->id]);

    try {
        app(AssignTicket::class)->handle($this->ticket, $this->tech, Fx::dispatcher());
        $this->fail('The assignment should have been refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('technician');
    }

    expect($this->ticket->visits()->count())->toBe(0);
});

it('assigns a ticket to an engineer who covers it', function () {
    app(SyncUserLocationScopes::class)->handle($this->tech, [$this->chain['province']->id]);

    $assigned = app(AssignTicket::class)->handle($this->ticket, $this->tech, Fx::dispatcher());

    expect($assigned->visits()->where('technician_id', $this->tech->id)->exists())->toBeTrue();
});
