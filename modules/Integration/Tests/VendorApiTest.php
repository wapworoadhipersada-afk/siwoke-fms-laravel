<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Integration\Application\Actions\CreateIntegrationClient;
use Modules\Integration\Application\Actions\RotateClientSecret;
use Modules\Integration\Application\Jobs\DeliverOutboundEvent;
use Modules\Integration\Application\Services\Signature;
use Modules\Integration\Domain\Models\IntegrationClient;
use Modules\Integration\Domain\Models\IntegrationLog;
use Modules\Integration\Domain\Models\OutboundEvent;
use Modules\Integration\Domain\Models\StatusMapping;
use Modules\Master\Tests\Support\MasterFixtures;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\TicketSource;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
    $this->terminal = Fx::terminal();
    $this->vendor = $this->terminal->vendor;
    $created = app(CreateIntegrationClient::class)->handle($this->vendor, 'Sistem tiket', [], null, $this->admin);
    $this->client = $created['client'];
    $this->secret = $created['secret'];
});

/**
 * Sends a request signed the way a vendor's system must.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $override  headers to replace, or null to drop
 */
function signed(object $test, string $method, string $uri, array $body = [], ?IntegrationClient $client = null, ?string $secret = null, array $override = [], ?string $nonce = null): TestResponse
{
    $client ??= $test->client;
    $secret ??= $test->secret;
    $content = $body === [] ? '' : (string) json_encode($body);
    $timestamp = (string) time();
    $nonce ??= (string) Str::uuid();
    $headers = [
        'X-Key-Id' => $client->key_id,
        'X-Timestamp' => $timestamp,
        'X-Nonce' => $nonce,
        'X-Signature' => Signature::sign($secret, $timestamp, $nonce, $method, $uri, $content),
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        ...$override,
    ];

    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $server['CONTENT_TYPE'] = 'application/json';

    return $test->call($method, $uri, [], [], [], $server, $content);
}

function ticketBody(object $test, array $extra = []): array
{
    return ['vendor_ticket_no' => 'BRI-'.Str::upper(Str::random(8)), 'tid' => $test->terminal->tid, 'type' => 'MAINTENANCE', ...$extra];
}

const VENDOR_TICKETS = '/api/vendor/v1/tickets';

describe('proving who is calling', function () {
    it('accepts a correctly signed request and records the call', function () {
        $body = ticketBody($this);

        $response = signed($this, 'POST', VENDOR_TICKETS, $body)->assertCreated();

        $ticket = Ticket::query()->where('vendor_ticket_no', $body['vendor_ticket_no'])->sole();
        $log = IntegrationLog::query()->latest('id')->firstOrFail();

        expect($ticket->source)->toBe(TicketSource::Api)
            ->and($ticket->created_by)->toBeNull()
            ->and($ticket->vendor_id)->toBe($this->vendor->id)
            ->and($ticket->payload['tid'])->toBe($this->terminal->tid)
            ->and($response->json('data.ticket_no'))->toBe($ticket->ticket_no)
            ->and($response->headers->get('X-Correlation-Id'))->toBe($log->correlation_id)
            ->and($log->direction)->toBe('in')
            ->and($log->status_code)->toBe(201)
            ->and($log->client_id)->toBe($this->client->id)
            ->and($log->ticket_id)->toBe($ticket->id)
            ->and(array_change_key_case($log->request_headers))->not->toHaveKeys(['x-signature', 'x-nonce', 'authorization']);
    });

    it('gives every failure to prove identity the same plain answer', function (string $case) {
        $override = match ($case) {
            'unknown key' => ['X-Key-Id' => 'fms_doesnotexist'],
            'bad signature' => ['X-Signature' => str_repeat('a', 64)],
            'stale timestamp' => [],
            'missing headers' => ['X-Signature' => ''],
        };

        if ($case === 'stale timestamp') {
            $old = (string) (time() - 3600);
            $nonce = (string) Str::uuid();
            $body = (string) json_encode(ticketBody($this));
            $override = ['X-Timestamp' => $old, 'X-Nonce' => $nonce, 'X-Signature' => Signature::sign($this->secret, $old, $nonce, 'POST', VENDOR_TICKETS, $body)];
            $response = $this->call('POST', VENDOR_TICKETS, [], [], [], [
                'HTTP_X_KEY_ID' => $this->client->key_id, 'HTTP_X_TIMESTAMP' => $old, 'HTTP_X_NONCE' => $nonce,
                'HTTP_X_SIGNATURE' => $override['X-Signature'], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            ], $body);
        } else {
            $response = signed($this, 'POST', VENDOR_TICKETS, ticketBody($this), override: $override);
        }

        $response->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        expect(Ticket::query()->count())->toBe(0);
    })->with(['unknown key', 'bad signature', 'stale timestamp', 'missing headers']);

    it('refuses a body altered after signing', function () {
        $content = (string) json_encode(ticketBody($this));
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $signature = Signature::sign($this->secret, $timestamp, $nonce, 'POST', VENDOR_TICKETS, $content);

        $this->call('POST', VENDOR_TICKETS, [], [], [], [
            'HTTP_X_KEY_ID' => $this->client->key_id, 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        ], str_replace('MAINTENANCE', 'INSTALL', $content))->assertUnauthorized();
    });

    it('refuses to accept the same signed request twice', function () {
        $content = (string) json_encode(ticketBody($this));
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $server = [
            'HTTP_X_KEY_ID' => $this->client->key_id, 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => Signature::sign($this->secret, $timestamp, $nonce, 'POST', VENDOR_TICKETS, $content),
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        ];

        $this->call('POST', VENDOR_TICKETS, [], [], [], $server, $content)->assertCreated();
        $this->call('POST', VENDOR_TICKETS, [], [], [], $server, $content)->assertUnauthorized();
    });

    it('does not let a stranger use up a client\'s nonces', function () {
        $nonce = (string) Str::uuid();
        $this->call('POST', VENDOR_TICKETS, [], [], [], [
            'HTTP_X_KEY_ID' => $this->client->key_id, 'HTTP_X_TIMESTAMP' => (string) time(), 'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => str_repeat('b', 64), 'HTTP_ACCEPT' => 'application/json',
        ], '{}')->assertUnauthorized();

        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this), nonce: $nonce)->assertCreated();
    });

    it('turns away a client that has been switched off, and a vendor that has been deactivated', function () {
        $this->client->forceFill(['is_active' => false])->save();
        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this))->assertUnauthorized();

        $this->client->forceFill(['is_active' => true])->save();
        $this->vendor->forceFill(['is_active' => false])->save();
        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this))->assertUnauthorized();
    });

    it('honours the list of allowed addresses', function () {
        $this->client->forceFill(['allowed_ips' => ['203.0.113.0/24']])->save();

        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this))->assertForbidden()->assertJsonPath('code', 'ip_not_allowed');

        $this->client->forceFill(['allowed_ips' => ['127.0.0.1']])->save();
        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this))->assertCreated();
    });

    it('keeps the secret encrypted at rest and out of the audit trail', function () {
        $raw = DB::table('integration_clients')->where('id', $this->client->id)->value('secret');

        expect($raw)->not->toContain($this->secret)
            ->and($this->client->fresh()->secret)->toBe($this->secret);

        $logged = DB::table('activity_log')->where('log_name', 'integration')->get()->pluck('properties')->implode(' ');
        expect($logged)->not->toContain($this->secret);
    });
});

describe('opening and following tickets', function () {
    it('answers a repeated request with the original ticket instead of making another', function () {
        $body = ticketBody($this);

        $first = signed($this, 'POST', VENDOR_TICKETS, $body)->assertCreated();
        $second = signed($this, 'POST', VENDOR_TICKETS, $body)->assertOk();

        expect($second->json('data.ticket_no'))->toBe($first->json('data.ticket_no'))
            ->and(Ticket::query()->count())->toBe(1);
    });

    it('calls it a clash when the same number is sent for a different TID', function () {
        $body = ticketBody($this);
        signed($this, 'POST', VENDOR_TICKETS, $body)->assertCreated();
        $other = MasterFixtures::terminalIn($this->vendor, MasterFixtures::chain()['area']);

        signed($this, 'POST', VENDOR_TICKETS, [...$body, 'tid' => $other->tid])->assertStatus(409)->assertJsonPath('code', 'vendor_ticket_conflict');
    });

    it('refuses a TID that belongs to another vendor, as if it did not exist', function () {
        $foreign = Fx::terminal('OTHERBANK');

        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this, ['tid' => $foreign->tid]))->assertUnprocessable()->assertJsonValidationErrors('tid');
        expect(Ticket::query()->count())->toBe(0);
    });

    it('validates the request', function () {
        signed($this, 'POST', VENDOR_TICKETS, ['tid' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['vendor_ticket_no', 'type']);
        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this, ['type' => 'NOPE']))->assertUnprocessable()->assertJsonValidationErrors('type');
        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this, ['priority' => 'whenever']))->assertUnprocessable()->assertJsonValidationErrors('priority');
    });

    it('reports status in the vendor\'s own words when a mapping exists', function () {
        StatusMapping::query()->create(['vendor_id' => $this->vendor->id, 'internal_status' => 'received', 'vendor_status' => 'OPEN']);
        $body = ticketBody($this);
        signed($this, 'POST', VENDOR_TICKETS, $body)->assertCreated()->assertJsonPath('data.status', 'OPEN')->assertJsonPath('data.internal_status', 'received');

        signed($this, 'GET', VENDOR_TICKETS.'/'.$body['vendor_ticket_no'])->assertOk()->assertJsonPath('data.status', 'OPEN');
    });

    it('does not show one vendor another\'s tickets', function () {
        $foreign = Fx::ticket(Fx::terminal('OTHERBANK'), vendorTicketNo: 'THEIRS-1');

        signed($this, 'GET', VENDOR_TICKETS.'/THEIRS-1')->assertNotFound()->assertJsonPath('code', 'ticket_not_found');
        expect($foreign->exists)->toBeTrue();
    });

    it('lets the vendor withdraw a ticket that has not reached the merchant', function () {
        $body = ticketBody($this);
        signed($this, 'POST', VENDOR_TICKETS, $body)->assertCreated();

        signed($this, 'POST', VENDOR_TICKETS.'/'.$body['vendor_ticket_no'].'/cancel', ['reason' => 'Merchant batal'])->assertOk()->assertJsonPath('data.internal_status', 'cancelled');
        expect(Ticket::query()->sole()->status)->toBe(TicketStatus::Cancelled);
    });

    it('refuses to withdraw a ticket that is already finished', function () {
        $ticket = Fx::ticket($this->terminal, vendorTicketNo: 'DONE-1');
        $ticket->forceFill(['status' => TicketStatus::Cancelled])->save();

        signed($this, 'POST', VENDOR_TICKETS.'/DONE-1/cancel', ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'cannot_cancel');
    });
});

describe('telling the vendor', function () {
    beforeEach(function () {
        config(['integration.retry_backoff_seconds' => [60]]);
        $created = app(CreateIntegrationClient::class)->handle($this->vendor, 'Callback', [], 'https://93.184.216.34/fms/status', $this->admin);
        $this->hookClient = $created['client'];
        $this->hookSecret = $created['webhook_secret'];
        $this->client->forceFill(['is_active' => false])->save();
    });

    it('writes an outbox row with the status change and delivers it signed', function () {
        Http::fake(['93.184.216.34/*' => Http::response(['ok' => true], 200)]);

        $ticket = Fx::ticket($this->terminal, vendorTicketNo: 'BRI-777');

        $event = OutboundEvent::query()->sole();
        expect($event->status)->toBe(OutboundEvent::SENT)
            ->and($event->attempts)->toBe(1)
            ->and($event->ticket_id)->toBe($ticket->id)
            ->and($event->payload['ticket']['vendor_ticket_no'])->toBe('BRI-777');

        Http::assertSent(function (HttpRequest $request) use ($event): bool {
            $body = $request->body();
            $expected = Signature::sign($this->hookSecret, $request->header('X-Timestamp')[0], $request->header('X-Nonce')[0], 'POST', '/fms/status', $body);

            return $request->url() === 'https://93.184.216.34/fms/status'
                && hash_equals($expected, $request->header('X-Signature')[0])
                && $request->header('X-Key-Id')[0] === $this->hookClient->key_id
                && json_decode($body, true)['event_id'] === $event->ulid
                && json_decode($body, true)['ticket']['internal_status'] === 'received';
        });

        $log = IntegrationLog::query()->where('direction', 'out')->sole();
        expect($log->status_code)->toBe(200)->and($log->ticket_id)->toBe($ticket->id);
    });

    it('does not carry personal data of the technician', function () {
        Http::fake(['*' => Http::response('', 200)]);
        $ticket = Fx::handling(Fx::ticket($this->terminal, vendorTicketNo: 'BRI-778'), $technician = Fx::technician());

        $sent = OutboundEvent::query()->latest('id')->firstOrFail()->payload;

        expect(json_encode($sent))->not->toContain($technician->name)->not->toContain((string) $technician->email);
        expect($ticket->exists)->toBeTrue();
    });

    it('says nothing about a ticket the vendor does not know by its own number', function () {
        Http::fake();

        Fx::ticket($this->terminal);

        expect(OutboundEvent::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('keeps trying when the vendor is down, then gives up and flags it for a person', function () {
        Http::fake(['*' => Http::response('boom', 500)]);

        Fx::ticket($this->terminal, vendorTicketNo: 'BRI-779');
        $event = OutboundEvent::query()->sole();

        expect($event->status)->toBe(OutboundEvent::PENDING)
            ->and($event->attempts)->toBe(1)
            ->and($event->next_attempt_at->isFuture())->toBeTrue()
            ->and($event->last_error)->toContain('500');

        // Not yet due: waking it again changes nothing.
        DeliverOutboundEvent::dispatchSync($event->id);
        expect($event->fresh()->attempts)->toBe(1);

        $event->forceFill(['next_attempt_at' => now()->subSecond()])->save();
        DeliverOutboundEvent::dispatchSync($event->id);

        expect($event->fresh()->status)->toBe(OutboundEvent::DEAD)->and($event->fresh()->attempts)->toBe(2);
        expect(IntegrationLog::query()->where('direction', 'out')->count())->toBe(2);
    });

    it('lets a person send a dead callback again from the log page', function () {
        Http::fake(['*' => Http::sequence()->push('boom', 500)->push('boom', 500)->push(['ok' => true], 200)]);
        Fx::ticket($this->terminal, vendorTicketNo: 'BRI-780');
        $event = OutboundEvent::query()->sole();
        $event->forceFill(['next_attempt_at' => now()->subSecond()])->save();
        DeliverOutboundEvent::dispatchSync($event->id);
        expect($event->fresh()->status)->toBe(OutboundEvent::DEAD);

        $this->actingAs($this->admin)->post(route('integration.outbound.retry', $event))->assertRedirect();

        expect($event->fresh()->status)->toBe(OutboundEvent::SENT);
    });

    it('refuses to call addresses inside our own network', function (string $url) {
        expect(fn () => app(CreateIntegrationClient::class)->handle($this->vendor, 'Jahat', [], $url, $this->admin))->toThrow(ValidationException::class);
    })->with([
        'private range' => 'https://10.0.0.5/hook',
        'loopback' => 'https://127.0.0.1/hook',
        'metadata address' => 'https://169.254.169.254/latest',
        'plain http' => 'http://93.184.216.34/hook',
        'credentials in url' => 'https://user:pass@93.184.216.34/hook',
    ]);

    it('re-checks the address when sending, in case it was re-pointed after saving', function () {
        Http::fake();
        // Saved while it was fine, re-pointed inside the network afterwards.
        $this->hookClient->forceFill(['webhook_url' => 'https://10.1.2.3/hook'])->save();

        Fx::ticket($this->terminal, vendorTicketNo: 'BRI-781');

        Http::assertNothingSent();
        expect(OutboundEvent::query()->sole()->last_error)->toContain('internal');
    });
});

describe('administration', function () {
    it('shows the new secret once, on the page that answers the creation', function () {
        $response = $this->actingAs($this->admin)->post(route('integration.clients.store'), [
            'vendor' => $this->vendor->ulid,
            'name' => 'Klien baru',
            'allowed_ips' => "203.0.113.5\n198.51.100.0/24",
        ]);

        $response->assertOk()->assertInertia(fn ($page) => $page->component('integration/clients/Credentials')->has('secret')->where('webhook_secret', null));

        $client = IntegrationClient::query()->where('name', 'Klien baru')->sole();
        expect($client->allowed_ips)->toBe(['203.0.113.5', '198.51.100.0/24']);

        $this->actingAs($this->admin)->get(route('integration.clients.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('integration/clients/Index')->missing('clients.0.secret')->missing('secret'));
    });

    it('rejects a malformed address list', function () {
        $this->actingAs($this->admin)->post(route('integration.clients.store'), ['vendor' => $this->vendor->ulid, 'name' => 'X', 'allowed_ips' => 'not-an-ip'])
            ->assertSessionHasErrors('allowed_ips');
    });

    it('replaces the secret on rotation and the old one stops working at once', function () {
        $rotated = app(RotateClientSecret::class)->handle($this->client, $this->admin);

        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this))->assertUnauthorized();
        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this), secret: $rotated['secret'])->assertCreated();
    });

    it('is closed to people without the permission', function () {
        $coordinator = User::factory()->create()->assignRole(RoleName::Coordinator->value);

        $this->actingAs($coordinator)->get(route('integration.clients.index'))->assertForbidden();
        $this->actingAs($coordinator)->get(route('integration.logs.index'))->assertForbidden();
    });

    it('lets management read the logs but not manage clients', function () {
        $manager = User::factory()->create()->assignRole(RoleName::Managerial->value);

        $this->actingAs($manager)->get(route('integration.logs.index'))->assertOk();
        $this->actingAs($manager)->get(route('integration.clients.index'))->assertForbidden();
    });

    it('can switch a client off and on', function () {
        $this->actingAs($this->admin)->post(route('integration.clients.toggle', $this->client))->assertRedirect();
        expect($this->client->fresh()->is_active)->toBeFalse();

        $this->actingAs($this->admin)->post(route('integration.clients.toggle', $this->client))->assertRedirect();
        expect($this->client->fresh()->is_active)->toBeTrue();
    });
});

describe('the log', function () {
    it('cannot be changed', function () {
        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this));
        $log = IntegrationLog::query()->firstOrFail();

        expect(fn () => DB::table('integration_logs')->where('id', $log->id)->update(['status_code' => 200]))->toThrow(QueryException::class);
    });

    it('cannot be erased', function () {
        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this));
        $log = IntegrationLog::query()->firstOrFail();

        expect(fn () => DB::table('integration_logs')->where('id', $log->id)->delete())->toThrow(QueryException::class);
    });

    it('records even the calls it turned away', function () {
        signed($this, 'POST', VENDOR_TICKETS, ticketBody($this), override: ['X-Signature' => str_repeat('c', 64)])->assertUnauthorized();

        $log = IntegrationLog::query()->firstOrFail();
        expect($log->status_code)->toBe(401)->and($log->error)->toBe('bad_signature')->and($log->client_id)->toBeNull();
    });
});

it('has a command that wakes up callbacks that are due', function () {
    Http::fake(['*' => Http::response('', 200)]);
    config(['integration.retry_backoff_seconds' => [60]]);
    app(CreateIntegrationClient::class)->handle($this->vendor, 'Callback', [], 'https://93.184.216.34/fms/status', $this->admin);
    $this->client->forceFill(['is_active' => false])->save();
    Fx::ticket($this->terminal, vendorTicketNo: 'BRI-900');
    OutboundEvent::query()->sole()->forceFill(['status' => OutboundEvent::PENDING, 'attempts' => 0, 'sent_at' => null, 'next_attempt_at' => now()->subMinute()])->save();

    $this->artisan('integration:deliver-due')->assertSuccessful();

    expect(OutboundEvent::query()->sole()->status)->toBe(OutboundEvent::SENT);
});
