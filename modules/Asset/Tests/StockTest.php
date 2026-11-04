<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\AdjustStock;
use Modules\Asset\Application\Actions\CancelHandover;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\Actions\RejectHandover;
use Modules\Asset\Application\Actions\RequestHandover;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\StockBook;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetStock;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Tests\Support\MasterFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);
    $this->vendor = MasterFixtures::vendor('BRI');
    $this->warehouse = Fx::warehouse();
    $this->tech = Fx::technician();
    $this->leader = Fx::approver();
    $this->paper = Fx::paperModel();
    Fx::receiveStock($this->paper, $this->vendor, $this->warehouse, 100, $this->leader);
});

function balance(object $test, Holder $holder): int
{
    return (int) AssetStock::query()
        ->where('asset_model_id', $test->paper->id)
        ->where('holder_type', $holder->type->value)
        ->where('holder_id', $holder->id)
        ->value('quantity');
}

function offerPaper(object $test, int $quantity): AssetHandover
{
    return Fx::offer($test->warehouse, $test->tech, [MoveItem::stock($test->paper, $test->vendor, $quantity)], $test->leader);
}

it('moves a quantity from the warehouse to a technician when accepted', function () {
    $handover = offerPaper($this, 30);

    // Offered but not yet accepted: the balance has not moved.
    expect(balance($this, Holder::warehouse($this->warehouse)))->toBe(100)
        ->and(balance($this, Holder::user($this->tech)))->toBe(0);

    app(AcceptHandover::class)->handle($handover, $this->tech);

    expect(balance($this, Holder::warehouse($this->warehouse)))->toBe(70)
        ->and(balance($this, Holder::user($this->tech)))->toBe(30);
});

describe('reservations', function () {
    it('counts what pending handovers have promised against what is available', function () {
        offerPaper($this, 60);

        $available = app(StockBook::class)->available($this->paper->id, $this->vendor->id, Holder::warehouse($this->warehouse));

        expect($available)->toBe(40);
    });

    it('refuses to promise the same stock twice', function () {
        offerPaper($this, 60);

        offerPaper($this, 50);
    })->throws(ValidationException::class, 'tidak cukup');

    it('refuses several lines that together exceed the balance', function () {
        Fx::offer($this->warehouse, $this->tech, [
            MoveItem::stock($this->paper, $this->vendor, 70),
            MoveItem::stock($this->paper, $this->vendor, 40),
        ], $this->leader);
    })->throws(ValidationException::class, 'tidak cukup');

    it('frees the reservation when the handover is rejected or cancelled', function () {
        $rejected = offerPaper($this, 60);
        app(RejectHandover::class)->handle($rejected, $this->tech, 'Tidak butuh');

        $cancelled = offerPaper($this, 60);
        app(CancelHandover::class)->handle($cancelled, $this->leader);

        expect(app(StockBook::class)->available($this->paper->id, $this->vendor->id, Holder::warehouse($this->warehouse)))->toBe(100);

        offerPaper($this, 100);
    });

    it('fails to accept if the stock was used up in the meantime', function () {
        $handover = offerPaper($this, 60);
        app(AdjustStock::class)->handle($this->paper, $this->vendor, Holder::warehouse($this->warehouse), -80, $this->leader, 'Hasil opname: rusak kena banjir');

        app(AcceptHandover::class)->handle($handover, $this->tech);
    })->throws(ValidationException::class, 'tidak cukup');
});

describe('using stock up', function () {
    beforeEach(function () {
        app(AcceptHandover::class)->handle(offerPaper($this, 30), $this->tech);
    });

    it('takes consumed quantity off the technician balance', function () {
        $movement = app(MoveAssets::class)->handle(new MovementCommand(
            MovementType::Consume, [MoveItem::stock($this->paper, $this->vendor, 5)], Holder::user($this->tech), Holder::external(), $this->tech,
            referenceType: 'ticket', referenceId: 12,
        ));

        expect(balance($this, Holder::user($this->tech)))->toBe(25)
            ->and($movement->items->sole()->quantity)->toBe(5)
            ->and($movement->reference_id)->toBe(12);
    });

    it('never lets a balance go below zero', function () {
        app(MoveAssets::class)->handle(new MovementCommand(
            MovementType::Consume, [MoveItem::stock($this->paper, $this->vendor, 31)], Holder::user($this->tech), Holder::external(), $this->tech,
        ));
    })->throws(ValidationException::class, 'tidak cukup');

    it('leaves the balance alone when a consumption fails', function () {
        expect(fn () => app(MoveAssets::class)->handle(new MovementCommand(
            MovementType::Consume, [MoveItem::stock($this->paper, $this->vendor, 99)], Holder::user($this->tech), Holder::external(), $this->tech,
        )))->toThrow(ValidationException::class)
            ->and(balance($this, Holder::user($this->tech)))->toBe(30);
    });

    it('rejects a zero or negative quantity', function (int $quantity) {
        app(MoveAssets::class)->handle(new MovementCommand(
            MovementType::Consume, [MoveItem::stock($this->paper, $this->vendor, $quantity)], Holder::user($this->tech), Holder::external(), $this->tech,
        ));
    })->with([0, -3])->throws(ValidationException::class, 'Jumlah');

    it('returns the remainder to the warehouse through a handover', function () {
        $handover = app(RequestHandover::class)->handle(new MovementCommand(
            MovementType::ReturnToWarehouse, [MoveItem::stock($this->paper, $this->vendor, 20)], Holder::user($this->tech), Holder::warehouse($this->warehouse), $this->tech,
        ));
        app(AcceptHandover::class)->handle($handover, $this->leader);

        expect(balance($this, Holder::user($this->tech)))->toBe(10)
            ->and(balance($this, Holder::warehouse($this->warehouse)))->toBe(90);
    });

    it('keeps vendors apart: BRI paper is not CIMB paper', function () {
        app(MoveAssets::class)->handle(new MovementCommand(
            MovementType::Consume, [MoveItem::stock($this->paper, MasterFixtures::vendor('CIMB'), 1)], Holder::user($this->tech), Holder::external(), $this->tech,
        ));
    })->throws(ValidationException::class, 'tidak cukup');
});

describe('stock counts', function () {
    it('corrects a balance upward or downward and keeps the reason', function () {
        $up = app(AdjustStock::class)->handle($this->paper, $this->vendor, Holder::warehouse($this->warehouse), 12, $this->leader, 'Ditemukan 12 roll belum tercatat');
        $down = app(AdjustStock::class)->handle($this->paper, $this->vendor, Holder::warehouse($this->warehouse), -7, $this->leader, 'Hilang saat pemindahan');

        expect(balance($this, Holder::warehouse($this->warehouse)))->toBe(105)
            ->and($up->type)->toBe(MovementType::Adjustment)
            ->and($up->notes)->toBe('Ditemukan 12 roll belum tercatat')
            ->and($down->items->sole()->quantity)->toBe(-7);
    });

    it('refuses to adjust below zero', function () {
        app(AdjustStock::class)->handle($this->paper, $this->vendor, Holder::warehouse($this->warehouse), -101, $this->leader, 'Salah hitung');
    })->throws(ValidationException::class, 'tidak cukup');

    it('requires a reason and a non-zero difference', function () {
        expect(fn () => app(AdjustStock::class)->handle($this->paper, $this->vendor, Holder::warehouse($this->warehouse), 5, $this->leader, ' '))
            ->toThrow(ValidationException::class, 'Alasan')
            ->and(fn () => app(AdjustStock::class)->handle($this->paper, $this->vendor, Holder::warehouse($this->warehouse), 0, $this->leader, 'Cek'))
            ->toThrow(ValidationException::class, 'tidak boleh nol');
    });

    it('applies only to consumables', function () {
        app(AdjustStock::class)->handle(Fx::edcModel(), $this->vendor, Holder::warehouse($this->warehouse), 1, $this->leader, 'Coba');
    })->throws(ValidationException::class, 'berbasis jumlah');
});

it('refuses to treat a consumable as a serialized unit and the reverse', function () {
    $edc = Fx::edcModel();
    Fx::receive($edc, $this->vendor, $this->warehouse, ['SN-1']);

    expect(fn () => Fx::offer($this->warehouse, $this->tech, [MoveItem::stock($edc, $this->vendor, 1)], $this->leader))
        ->toThrow(ValidationException::class);
});
