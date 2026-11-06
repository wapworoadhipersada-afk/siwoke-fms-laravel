<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Web\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\IAM\Application\Actions\SaveUserPhoto;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ModuleController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profile pictures. A person may see and change their own; people who manage users may see and change anyone's.
 * The file is streamed through here, never linked directly, so it follows the same access rule as the page.
 */
final class UserPhotoController extends ModuleController
{
    public function show(Request $request, User $managedUser): Response
    {
        $viewer = $this->user();

        abort_unless($viewer->is($managedUser) || $viewer->can(Permissions::IAM_USER_VIEW), 404);

        $thumb = $request->boolean('thumb');
        $path = $thumb ? ($managedUser->photo_thumb_path ?? $managedUser->photo_path) : $managedUser->photo_path;
        $disk = Storage::disk((string) config('documents.disk'));

        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->response($path, $managedUser->ulid.($thumb ? '_thumb' : '').'.jpg', [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function update(Request $request, User $managedUser, SaveUserPhoto $action): RedirectResponse
    {
        $this->authorizeChange($managedUser);

        $request->validate(['photo' => ['required', 'file']]);

        $action->handle($managedUser, $request->file('photo'), $this->user());
        $this->success('Foto profil disimpan.');

        return back();
    }

    public function destroy(User $managedUser, SaveUserPhoto $action): RedirectResponse
    {
        $this->authorizeChange($managedUser);

        $action->remove($managedUser, $this->user());
        $this->success('Foto profil dihapus.');

        return back();
    }

    private function authorizeChange(User $target): void
    {
        $actor = $this->user();

        abort_unless($actor->is($target) || $actor->can(Permissions::IAM_USER_UPDATE), 403);
    }
}
