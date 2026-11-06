<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Queries\BuildTeamTree;
use Modules\Master\Application\Services\VendorAccess;

/**
 * "Tim": the field organisation per vendor, from coordinator to leader to engineer. Read-only; who is
 * responsible for what is changed on each person's coverage screen.
 */
final class TeamController extends MasterController
{
    public function index(BuildTeamTree $query, VendorAccess $vendors): Response
    {
        $this->allow(Permissions::IAM_USER_VIEW);

        $user = $this->user();
        $focused = $vendors->focusedId($user);

        return Inertia::render('master/team/Index', [
            'teams' => $query->handle($focused === null ? $vendors->selectableIds($user) : [$focused]),
        ]);
    }
}
