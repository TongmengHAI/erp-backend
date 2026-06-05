<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Admin\Users;

use App\Domain\Identity\Enums\InvitationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query input for GET /api/v1/admin/users/invitations.
 *
 * The status filter accepts InvitationStatus enum values; the
 * controller routes through InvitationQueryService which selects
 * a SQL CASE WHEN to mirror the model's status() accessor.
 */
final class IndexInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $values = array_map(static fn (InvitationStatus $s): string => $s->value, InvitationStatus::cases());

        return [
            'status' => ['nullable', 'string', Rule::in($values)],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
