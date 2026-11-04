<?php

declare(strict_types=1);

namespace Modules\Shared\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Common helpers for module web controllers: permission checks, the signed-in user, toasts,
 * and re-keying action validation errors onto form field names.
 */
abstract class ModuleController extends Controller
{
    protected function allow(string $permission): void
    {
        Gate::authorize($permission);
    }

    protected function user(): User
    {
        $user = request()->user();
        assert($user instanceof User);

        return $user;
    }

    protected function success(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * Business-rule refusals (e.g. deleting something still in use) are shown as a toast, not a form error.
     */
    protected function refusal(ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->first();

        Inertia::flash('toast', ['type' => 'error', 'message' => is_string($message) ? $message : 'Aksi ditolak.']);
    }

    /**
     * Actions report errors under the column they validate (`parent_id`); forms name their field
     * differently (`parent`). Re-key the errors so they appear next to the right input.
     *
     * @template T
     *
     * @param  array<string, string>  $fieldMap
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function translatingErrors(array $fieldMap, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors[$fieldMap[$field] ?? $field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
