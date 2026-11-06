<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Document\Application\Actions\StoreAttachment;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Domain\Models\Vendor;
use Modules\Ticket\Application\Actions\AcceptTicket;
use Modules\Ticket\Application\Actions\ArriveAtTicket;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Actions\CancelTicket;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Application\Actions\CreateTicket;
use Modules\Ticket\Application\Actions\DepartForTicket;
use Modules\Ticket\Application\Actions\RecordAssetLine;
use Modules\Ticket\Application\Actions\RejectTicket;
use Modules\Ticket\Application\Actions\StartHandling;
use Modules\Ticket\Domain\Enums\AssetLineAction;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\ReasonCode;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketType;

/**
 * A few months of field work, played through the real actions with the clock moved back, so the ticket list,
 * the history, the evidence, the Berita Acara, the SLA and the reports all have something to show:
 * installs first (they put machines on TIDs), then replacements, pull-outs and maintenance, finished or
 * left in every in-between state.
 */
final class DemoTicketsSeeder extends Seeder
{
    private const OPEN_STATES = ['received' => 6, 'assigned' => 5, 'accepted' => 4, 'on_the_way' => 3, 'arrived' => 3, 'handling' => 3, 'pending' => 5, 'negative' => 3, 'cancelled' => 3, 'rejected' => 2, 'overdue' => 4];

    /** @var array<int, true> terminals that already have an unfinished ticket */
    private array $busy = [];

    /** @var array<int, list<int>> technician id => area ids */
    private array $coverage = [];

    private int $reference = 0;

    public function run(): void
    {
        DemoSupport::seed();
        mt_srand(9001);

        // Berita Acara is built by a queued listener; build it right away so every finished ticket has one.
        config(['queue.default' => 'sync']);

        $counts = ['finished' => 0, 'open' => 0];

        try {
            foreach (Vendor::query()->whereIn('code', ['BRI', 'CIMB'])->get() as $vendor) {
                $this->coverage = $this->coverageOf($vendor);
                $desk = User::query()->role(RoleName::OperationalAdmin->value)->whereIn('id', DB::table('user_vendors')->where('vendor_id', $vendor->id)->select('user_id'))->firstOrFail();
                $terminals = Terminal::query()->with('site')->where('vendor_id', $vendor->id)->inRandomOrder()->get();
                $pool = $terminals->shuffle()->values();

                // History, oldest first: installs, then work on the machines they put in place.
                $installed = [];

                for ($i = 0; $i < 24; $i++) {
                    $terminal = $pool->pop();
                    $ticket = $terminal === null ? null : $this->play($vendor, $desk, $terminal, 'INSTALL', mt_rand(60, 120), 'finished');

                    if ($ticket instanceof Ticket) {
                        $installed[] = $terminal;
                        $counts['finished']++;
                    }
                }

                $installed = collect($installed)->shuffle()->values();

                foreach ([['REPLACE', 6, [20, 50]], ['PULLOUT', 4, [10, 40]]] as [$type, $amount, [$from, $to]]) {
                    for ($i = 0; $i < $amount; $i++) {
                        $terminal = $installed->pop();

                        if ($terminal !== null && $this->play($vendor, $desk, $terminal, $type, mt_rand($from, $to), 'finished') instanceof Ticket) {
                            $counts['finished']++;
                        }
                    }
                }

                for ($i = 0; $i < 30; $i++) {
                    $terminal = $pool->random();

                    if ($this->play($vendor, $desk, $terminal, 'MAINTENANCE', mt_rand(1, 45), 'finished') instanceof Ticket) {
                        $counts['finished']++;
                    }
                }

                // Work still going on today, in every state a ticket can be in.
                foreach (self::OPEN_STATES as $state => $amount) {
                    for ($i = 0; $i < $amount; $i++) {
                        $terminal = $this->freeTerminal($pool);

                        if ($terminal instanceof Terminal && $this->play($vendor, $desk, $terminal, DemoSupport::pick(['MAINTENANCE', 'MAINTENANCE', 'INSTALL']), $state === 'overdue' ? mt_rand(5, 9) : mt_rand(0, 3), $state) instanceof Ticket) {
                            $counts['open']++;
                        }
                    }
                }
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->command?->info("{$counts['finished']} tiket selesai dan {$counts['open']} tiket yang masih berjalan, lengkap dengan bukti foto dan Berita Acara.");
    }

    /**
     * Plays one ticket from creation to the wanted state. Returns null when the scenario cannot be played
     * (for example no technician with a machine in hand covers the TID), so the caller simply moves on.
     */
    private function play(Vendor $vendor, User $desk, Terminal $terminal, string $typeCode, int $daysAgo, string $until): ?Ticket
    {
        if (isset($this->busy[$terminal->id]) && ! in_array($until, ['finished'], true)) {
            return null;
        }

        $needsMachine = in_array($typeCode, ['INSTALL', 'REPLACE'], true) && $until !== 'received';
        $technician = $until === 'received' || $until === 'cancelled' ? null : $this->technicianFor($terminal, $needsMachine);

        if ($technician === null && ! in_array($until, ['received', 'cancelled'], true)) {
            return null;
        }

        $clock = CarbonImmutable::now()->subDays($daysAgo)->setTime(mt_rand(7, 15), mt_rand(0, 59));
        $this->at($clock);

        $type = TicketType::query()->where('code', $typeCode)->firstOrFail();
        $ticket = app(CreateTicket::class)->handle(
            $terminal,
            $type,
            $desk,
            DemoSupport::pick([TicketPriority::Normal, TicketPriority::Normal, TicketPriority::High, TicketPriority::Low, TicketPriority::Urgent]),
            DemoSupport::chance(30) ? $clock->addDays(mt_rand(1, 3)) : null,
            $this->description($typeCode),
            sprintf('%s-INC-%06d', $vendor->code, ++$this->reference + 100000),
        );

        if ($until !== 'finished') {
            $this->busy[$terminal->id] = true;
        }

        if ($until === 'received') {
            return $ticket;
        }

        $clock = $this->at($clock->addMinutes(mt_rand(15, 90)));

        if ($until === 'cancelled') {
            app(CancelTicket::class)->handle($ticket, $desk, DemoSupport::pick(['Dibatalkan vendor', 'Merchant sudah tidak membutuhkan', 'Tiket ganda']));

            return $ticket;
        }

        $ticket = app(AssignTicket::class)->handle($ticket, $technician, $desk);

        if ($until === 'assigned' || $until === 'overdue') {
            return $ticket;
        }

        $clock = $this->at($clock->addMinutes(mt_rand(10, 60)));

        if ($until === 'rejected') {
            unset($this->busy[$terminal->id]);

            return app(RejectTicket::class)->handle($ticket, $technician, 'Jadwal bentrok dengan kunjungan lain');
        }

        $ticket = app(AcceptTicket::class)->handle($ticket, $technician);

        if ($until === 'accepted') {
            return $ticket;
        }

        $clock = $this->at($clock->addMinutes(mt_rand(10, 30)));

        if ($until === 'on_the_way' || DemoSupport::chance(70)) {
            $ticket = app(DepartForTicket::class)->handle($ticket, $technician);
            $clock = $this->at($clock->addMinutes(mt_rand(20, 70)));
        }

        if ($until === 'on_the_way') {
            return $ticket;
        }

        $ticket = app(ArriveAtTicket::class)->handle($ticket, $technician);

        if ($until === 'arrived') {
            return $ticket;
        }

        $clock = $this->at($clock->addMinutes(mt_rand(3, 15)));
        $ticket = app(StartHandling::class)->handle($ticket, $technician);

        if ($until === 'handling') {
            return $ticket;
        }

        $clock = $this->at($clock->addMinutes(mt_rand(15, 70)));

        return match ($until) {
            'finished' => $this->finish($ticket, $technician, $typeCode, $terminal),
            'pending' => $this->close($ticket, $technician, VisitOutcome::Pending, ReasonType::Pending, null),
            default => $this->close($ticket, $technician, VisitOutcome::DoneNegative, ReasonType::Negative, 'Sudah dicek di lokasi; pekerjaan tidak dapat dilanjutkan.'),
        };
    }

    private function finish(Ticket $ticket, User $technician, string $typeCode, Terminal $terminal): Ticket
    {
        $lines = app(RecordAssetLine::class);

        if (in_array($typeCode, ['INSTALL', 'REPLACE'], true)) {
            $machine = $this->machineInHands($technician);
            $lines->handle($ticket, $technician, AssetLineAction::Install, asset: $machine);
        }

        if (in_array($typeCode, ['PULLOUT', 'REPLACE'], true)) {
            $installed = Asset::query()->where('status', 'installed')->where('holder_type', 'terminal')->where('holder_id', $terminal->id)->firstOrFail();
            $lines->handle($ticket, $technician, AssetLineAction::Uninstall, asset: $installed);
        }

        if ($typeCode === 'MAINTENANCE' && DemoSupport::chance(35)) {
            $paper = AssetModel::query()->where('model_name', 'Roll 57mm')->firstOrFail();
            $lines->handle($ticket, $technician, AssetLineAction::Consume, model: $paper, quantity: mt_rand(1, 3));
        }

        $this->evidence($ticket, $technician, [AttachmentKind::PhotoBefore, AttachmentKind::PhotoAfter, AttachmentKind::SignatureMerchant]);

        return app(CloseVisit::class)->handle($ticket, $technician, VisitOutcome::Finish);
    }

    private function close(Ticket $ticket, User $technician, VisitOutcome $outcome, ReasonType $type, ?string $notes): Ticket
    {
        $reason = ReasonCode::query()->where('type', $type->value)->inRandomOrder()->firstOrFail();
        $this->evidence($ticket, $technician, [AttachmentKind::PhotoEvidence]);

        return app(CloseVisit::class)->handle($ticket, $technician, $outcome, reason: $reason, notes: $notes);
    }

    /**
     * @param  list<AttachmentKind>  $kinds
     */
    private function evidence(Ticket $ticket, User $technician, array $kinds): void
    {
        $visit = $ticket->visits()->whereNull('outcome')->firstOrFail();

        foreach ($kinds as $kind) {
            $isSignature = $kind->isSignature();
            $bytes = $isSignature ? DemoSupport::signature() : DemoSupport::photo($ticket->ticket_no.' · '.$kind->value);

            app(StoreAttachment::class)->handle($visit, $technician, DemoSupport::upload($bytes, $isSignature ? 'ttd.png' : 'foto.jpg'), $kind, now());
        }
    }

    /**
     * A technician who covers the TID's area and, when asked, has a machine in hand. The same few people do
     * most of the work in an area, as in real life.
     */
    private function technicianFor(Terminal $terminal, bool $needsMachine): ?User
    {
        $areaId = (int) $terminal->site->location_id;
        $candidates = array_keys(array_filter($this->coverage, static fn (array $areas): bool => in_array($areaId, $areas, true)));
        shuffle($candidates);

        foreach ($candidates as $id) {
            $technician = User::query()->find($id);

            if ($technician instanceof User && (! $needsMachine || $this->machineInHands($technician) instanceof Asset)) {
                return $technician;
            }
        }

        return null;
    }

    private function machineInHands(User $technician): ?Asset
    {
        return Asset::query()->where('status', 'with_technician')->where('holder_type', 'user')->where('holder_id', $technician->id)
            ->whereHas('model.category', fn ($query) => $query->where('code', 'EDC'))->orderBy('id')->first();
    }

    /**
     * @return array<int, list<int>>
     */
    private function coverageOf(Vendor $vendor): array
    {
        $result = [];

        $rows = DB::table('user_location_scopes as scope')
            ->join('user_vendors as uv', 'uv.user_id', '=', 'scope.user_id')
            ->join('model_has_roles as mr', 'mr.model_id', '=', 'scope.user_id')
            ->join('roles', 'roles.id', '=', 'mr.role_id')
            ->where('uv.vendor_id', $vendor->id)->where('roles.name', RoleName::Technician->value)
            ->get(['scope.user_id', 'scope.location_id']);

        foreach ($rows as $row) {
            $result[(int) $row->user_id][] = (int) $row->location_id;
        }

        return $result;
    }

    /**
     * @param  Collection<int, Terminal>  $pool
     */
    private function freeTerminal($pool): ?Terminal
    {
        foreach ($pool->shuffle() as $terminal) {
            if (! isset($this->busy[$terminal->id])) {
                return $terminal;
            }
        }

        return null;
    }

    private function at(CarbonImmutable $moment): CarbonImmutable
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);

        return $moment;
    }

    private function description(string $type): string
    {
        return match ($type) {
            'INSTALL' => DemoSupport::pick(['Pemasangan EDC baru untuk merchant baru', 'Pasang mesin tambahan di kasir kedua', 'Aktivasi TID baru']),
            'REPLACE' => DemoSupport::pick(['Mesin sering hang, ganti unit', 'Layar rusak, mohon diganti', 'Upgrade ke mesin baru']),
            'PULLOUT' => DemoSupport::pick(['Merchant tutup, tarik mesin', 'Merchant berhenti kerja sama', 'Relokasi mesin ke cabang lain']),
            default => DemoSupport::pick(['Kertas sering macet', 'Mesin tidak bisa terkoneksi', 'Printer tidak mencetak', 'Baterai cepat habis', 'Sinyal lemah, cek SIM']),
        };
    }
}
