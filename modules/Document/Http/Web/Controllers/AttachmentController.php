<?php

declare(strict_types=1);

namespace Modules\Document\Http\Web\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Document\Application\Services\AttachmentAccess;
use Modules\Document\Domain\Models\Attachment;
use Symfony\Component\HttpFoundation\Response;

final class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentAccess $access) {}

    public function show(Request $request, Attachment $attachment): Response
    {
        return $this->access->respond($this->user($request), $attachment);
    }

    public function thumbnail(Request $request, Attachment $attachment): Response
    {
        return $this->access->respond($this->user($request), $attachment, thumbnail: true);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
