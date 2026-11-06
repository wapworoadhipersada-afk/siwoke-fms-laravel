<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Modules\IAM\Domain\FieldAccount;
use Modules\IAM\Domain\Models\NavigationMenu;

/**
 * Works out the breadcrumb of the page being shown, so no page has to declare its own.
 *
 * The sidebar already knows where the main pages sit (group, then page), and Laravel's resource route names
 * already say what a screen is (`tickets.create` is "Tambah" under `tickets.index`). Both are used here:
 *
 *  1. a page that is a sidebar entry shows its group and its own name, as the administrator named them;
 *  2. a page that is not (an add, change or detail screen) hangs under the index page of its resource;
 *  3. the few pages that are neither are listed in PAGES with the page they belong under.
 *
 * Because the trail is built on the server from the same data as the sidebar, renaming or regrouping a menu
 * changes every breadcrumb that passes through it, and a new screen follows its resource without any edit.
 */
final class BreadcrumbTrail
{
    /**
     * Pages that are in no sidebar group: route name => [label, parent route name or null].
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const PAGES = [
        'iam.roles.index' => ['Pengaturan Peran', 'iam.settings.index'],
        'iam.navigation.index' => ['Pengaturan Menu', 'iam.settings.index'],
        'iam.navigation.access' => ['Menu per Peran', 'iam.settings.index'],
        'master.locations.index' => ['Lokasi', null],
        'profile.edit' => ['Profil', null],
        'security.edit' => ['Keamanan', null],
        'appearance.edit' => ['Tampilan', null],
    ];

    /**
     * Resources whose screens have no index page of their own: resource => the index page they belong under.
     *
     * @var array<string, string>
     */
    private const BELONGS_TO = [
        'master.sites' => 'master.merchants.index',
    ];

    /**
     * What the last part of a route name means for the person reading the trail.
     *
     * @var array<string, string>
     */
    private const ACTIONS = [
        'create' => 'Tambah',
        'store' => 'Tambah',
        'edit' => 'Ubah',
        'update' => 'Ubah',
        'show' => 'Detail',
        'reset-password' => 'Password baru',
    ];

    /** @var array<string, NavigationMenu>|null */
    private ?array $entries = null;

    /**
     * @return list<array{title: string, href: string|null}> empty for the dashboard and for unnamed routes
     */
    public function forRoute(?Route $route): array
    {
        $name = $route?->getName();

        if ($route === null || $name === null || $name === 'dashboard') {
            return [];
        }

        $trail = [['title' => 'Beranda', 'href' => route('dashboard', absolute: false)]];

        if ($name === 'master.user-scopes.edit') {
            return [...$trail, ...$this->coverageTrail($route)];
        }

        if ($this->known($name)) {
            return [...$trail, ...$this->pageTrail($name, true)];
        }

        $segments = explode('.', $name);
        $action = array_pop($segments);
        $base = implode('.', $segments);

        $anchor = self::BELONGS_TO[$base] ?? $this->indexOf($segments);

        if ($anchor !== null) {
            $trail = [...$trail, ...$this->pageTrail($anchor, false)];
        }

        $trail[] = ['title' => self::ACTIONS[$action] ?? Str::headline($action), 'href' => null];

        return $trail;
    }

    /**
     * The nearest index page at or above this resource: tickets.create -> tickets.index, and
     * master.merchants.sites.create -> master.merchants.index.
     *
     * @param  list<string>  $segments
     */
    private function indexOf(array $segments): ?string
    {
        while ($segments !== []) {
            $candidate = implode('.', $segments).'.index';

            if ($this->known($candidate)) {
                return $candidate;
            }

            array_pop($segments);
        }

        return null;
    }

    private function known(string $name): bool
    {
        return (isset(self::PAGES[$name]) || isset($this->entries()[$name])) && RouteFacade::has($name);
    }

    /**
     * Group (if any), then the page. The page itself is a link unless it is the one being shown.
     *
     * @return list<array{title: string, href: string|null}>
     */
    private function pageTrail(string $name, bool $current): array
    {
        $href = $current ? null : route($name, absolute: false);

        if (isset(self::PAGES[$name])) {
            [$label, $parent] = self::PAGES[$name];

            return [...($parent === null ? [] : $this->pageTrail($parent, false)), ['title' => $label, 'href' => $href]];
        }

        $entry = $this->entries()[$name];
        $trail = [];

        // A group is a heading, not a page: it names the section but leads nowhere.
        if ($entry->parent instanceof NavigationMenu) {
            $trail[] = ['title' => $entry->parent->label, 'href' => null];
        }

        $trail[] = ['title' => $entry->label, 'href' => $href];

        return $trail;
    }

    /**
     * Area and vendor coverage belongs to a person: it hangs under that person's change screen, which in turn
     * hangs under Teknisi or Pengguna, depending on who they are.
     *
     * @return list<array{title: string, href: string|null}>
     */
    private function coverageTrail(Route $route): array
    {
        $person = $route->parameter('managedUser');

        if (! $person instanceof User) {
            return [['title' => 'Area dan vendor', 'href' => null]];
        }

        $field = FieldAccount::is($person);

        return [
            ...$this->pageTrail($field ? 'iam.engineers.index' : 'iam.users.index', false),
            ['title' => 'Ubah', 'href' => route($field ? 'iam.engineers.edit' : 'iam.users.edit', $person->ulid, absolute: false)],
            ['title' => 'Area dan vendor', 'href' => null],
        ];
    }

    /**
     * @return array<string, NavigationMenu>
     */
    private function entries(): array
    {
        return $this->entries ??= NavigationMenu::query()->with('parent')->whereNotNull('route_name')->get()->keyBy('route_name')->all();
    }
}
