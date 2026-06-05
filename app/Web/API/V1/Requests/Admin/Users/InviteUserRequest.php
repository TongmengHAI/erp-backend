<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Admin\Users;

use App\Domain\Identity\Models\Invitation;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for POST /api/v1/admin/users/invitations.
 *
 * Two cross-cutting invariants enforced via withValidator (NOT field-
 * level rules) so both reach the same 422 error_code surface:
 *
 *   email_globally_registered — email maps to a users row in ANY
 *     tenant (Phase 2A Option A: users.email is GLOBALLY unique).
 *     Check uses ->withTrashed() to mirror the underlying UNIQUE
 *     constraint, which also includes soft-deleted rows.
 *
 *   active_invitation_exists — there is already a pending invitation
 *     for (tenant_id, email). Per Q11: admin should re-send the
 *     existing invitation rather than create a duplicate.
 *
 * Both yield a 422 with `errors.email` populated AND a top-level
 * `error_code` field that the SPA branches on (renders an inline
 * "re-send instead" link for active_invitation_exists; a
 * deactivate-existing prompt for email_globally_registered).
 *
 * Triple-stack discipline per §10.4: this layer + the Action's
 * preflight + the DB's partial unique index. Three independent
 * gates; drift between them surfaces in tests.
 */
final class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'max:255'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var array{email?: string, name?: string|null, role_id?: int} $data */
            $data = $v->validated();
            $email = $data['email'] ?? null;
            $actor = $this->user();

            if ($email === null || $actor === null || $actor->tenant_id === null) {
                return;
            }

            // email_globally_registered — Phase 2A Option A.
            $emailAlreadyUser = User::query()
                ->withTrashed()
                ->where('email', $email)
                ->exists();
            if ($emailAlreadyUser) {
                $v->errors()->add(
                    'email',
                    'This email is already registered to another organization.'
                );
                // Store the error_code on the request so the controller
                // can surface it in the 422 body alongside Laravel's
                // standard errors map.
                $this->attributes->set('error_code', 'email_globally_registered');

                return;
            }

            // active_invitation_exists — Q11. The check mirrors the
            // partial unique index's WHERE clause.
            $activeInvitation = Invitation::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('cancelled_at')
                ->whereNull('deleted_at')
                ->where('expires_at', '>=', now())
                ->first();
            if ($activeInvitation !== null) {
                $v->errors()->add(
                    'email',
                    'An active invitation already exists for this email. Re-send the existing invitation instead.'
                );
                $this->attributes->set('error_code', 'active_invitation_exists');
                $this->attributes->set('existing_invitation_id', $activeInvitation->id);
            }
        });
    }
}
