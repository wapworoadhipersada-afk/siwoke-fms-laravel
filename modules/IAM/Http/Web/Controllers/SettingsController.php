<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Web\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ModuleController;

/**
 * "Pengaturan": one page of cards that lead to each setting screen. A card appears only for people who may
 * open the screen behind it, so the hub never offers a door that is locked.
 */
final class SettingsController extends ModuleController
{
    public function index(): Response
    {
        $this->allow(Permissions::IAM_ROLE_MANAGE);

        $user = $this->user();

        $cards = [
            ['title' => 'Pengaturan Peran', 'description' => 'Buat peran dan tentukan apa yang boleh dilakukan tiap peran.', 'href' => route('iam.roles.index', absolute: false), 'icon' => 'users', 'show' => $user->can(Permissions::IAM_ROLE_MANAGE)],
            ['title' => 'Pengaturan Menu', 'description' => 'Buat group menu, tambah menu, atur alamat, halaman, dan urutannya.', 'href' => route('iam.navigation.index', absolute: false), 'icon' => 'list', 'show' => $user->can(Permissions::IAM_ROLE_MANAGE)],
            ['title' => 'Menu per Peran', 'description' => 'Pilih menu apa saja yang tampil untuk tiap peran dan urutannya.', 'href' => route('iam.navigation.access', absolute: false), 'icon' => 'gear', 'show' => $user->can(Permissions::IAM_ROLE_MANAGE)],
        ];

        return Inertia::render('iam/settings/Index', [
            'cards' => array_values(array_filter($cards, static fn (array $card): bool => $card['show'])),
        ]);
    }
}
