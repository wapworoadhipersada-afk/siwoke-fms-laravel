<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Master\Domain\Models\Terminal;

/**
 * Turns holders (a type and an id) into names people can read, in one query per type
 * instead of one per row.
 */
final class HolderLabeler
{
    /**
     * @param  iterable<Holder>  $holders
     * @return array<string, string> label per "type:id" key; see key()
     */
    public function labels(iterable $holders): array
    {
        $ids = [];

        foreach ($holders as $holder) {
            if ($holder->id !== null) {
                $ids[$holder->type->value][] = $holder->id;
            }
        }

        $labels = [];

        foreach (Warehouse::query()->withTrashed()->whereIn('id', $ids[HolderType::Warehouse->value] ?? [])->get() as $warehouse) {
            $labels[$this->key(Holder::warehouse($warehouse))] = "Gudang {$warehouse->name}";
        }

        foreach (User::query()->withTrashed()->whereIn('id', $ids[HolderType::User->value] ?? [])->get() as $user) {
            $labels[$this->key(Holder::user($user))] = $user->name;
        }

        $terminals = Terminal::query()->withTrashed()->with('merchant')->whereIn('id', $ids[HolderType::Terminal->value] ?? [])->get();

        foreach ($terminals as $terminal) {
            $labels[$this->key(Holder::terminal($terminal))] = "TID {$terminal->tid} · {$terminal->merchant->name}";
        }

        return $labels;
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function resolve(array $labels, Holder $holder): string
    {
        if ($holder->type === HolderType::External) {
            return $holder->type->label();
        }

        return $labels[$this->key($holder)] ?? "{$holder->type->label()} #{$holder->id}";
    }

    public function key(Holder $holder): string
    {
        return $holder->type->value.':'.($holder->id ?? 0);
    }

    /**
     * @param  Collection<int, Holder>  $holders
     * @return array<string, string>
     */
    public function labelsFor(Collection $holders): array
    {
        return $this->labels($holders->all());
    }
}
