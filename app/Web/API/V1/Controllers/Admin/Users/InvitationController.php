<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Domain\Identity\Actions\InviteUserAction;
use App\Domain\Identity\Services\InvitationQueryService;
use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\Admin\Users\IndexInvitationsRequest;
use App\Web\API\V1\Requests\Admin\Users\InviteUserRequest;
use App\Web\API\V1\Resources\Admin\AdminInvitationBriefResource;
use App\Web\API\V1\Resources\Admin\AdminInvitationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Resource controller for /admin/users/invitations.
 *
 *   GET   /admin/users/invitations         index — paginated list with
 *                                                  computed status filter
 *   POST  /admin/users/invitations         store — invite a new user
 *
 * Cancel + resend are dedicated invokable controllers per §10.2
 * (CancelInvitationController, ResendInvitationController) — NOT
 * methods here.
 *
 * Lifetime config: INVITATION_TOKEN_LIFETIME_DAYS (default 7) per
 * the Phase 2A locked decision.
 */
final class InvitationController extends Controller
{
    use AuthorizesUserManagement;

    public function index(
        IndexInvitationsRequest $request,
        InvitationQueryService $queryService,
    ): AnonymousResourceCollection {
        $this->authorizeUsersAccess($request);

        $actor = $request->user();
        /** @var array{status?: string, search?: string, per_page?: int} $filters */
        $filters = $request->validated();

        $query = $queryService->query()
            ->where('invitations.tenant_id', $actor?->tenant_id);

        // Postgres doesn't permit HAVING on a SELECT alias outside an
        // aggregate context, so the status filter translates the
        // CASE WHEN's branches into WHERE clauses. Same semantics as
        // the model's status() accessor + the SQL CASE WHEN — drift
        // between any of the three breaks the status-filter contract.
        if (isset($filters['status'])) {
            match ($filters['status']) {
                'accepted' => $query->whereNotNull('accepted_at'),
                'cancelled' => $query->whereNull('accepted_at')->whereNotNull('cancelled_at'),
                'expired' => $query->whereNull('accepted_at')
                    ->whereNull('cancelled_at')
                    ->where('expires_at', '<', now()),
                'pending' => $query->whereNull('accepted_at')
                    ->whereNull('cancelled_at')
                    ->where('expires_at', '>=', now()),
                default => null,
            };
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search): void {
                $q->where('invitations.email', 'ilike', $search)
                    ->orWhere('invitations.name', 'ilike', $search);
            });
        }

        $perPage = $filters['per_page'] ?? 15;
        $page = $query->orderByDesc('invitations.created_at')->paginate($perPage);

        return AdminInvitationBriefResource::collection($page);
    }

    public function store(
        InviteUserRequest $request,
        InviteUserAction $action,
    ): JsonResponse {
        $this->authorizeUsersAccess($request);
        $this->authorizeUsersAction($request, 'users.invite');

        // Surface the FormRequest's error_code (set by withValidator
        // for email_globally_registered / active_invitation_exists)
        // BEFORE the Action runs. If $request->validated() reached
        // this controller, validation passed; this branch is for
        // when the Validator added errors via $v->errors()->add but
        // Laravel's automatic 422 didn't catch it — defensive only,
        // normally unreachable.
        $errorCode = $request->attributes->get('error_code');
        if ($errorCode !== null) {
            $existingId = $request->attributes->get('existing_invitation_id');
            $body = ['error_code' => $errorCode];
            if ($existingId !== null) {
                $body['existing_invitation_id'] = $existingId;
            }

            return response()->json($body, 422);
        }

        /** @var array{email: string, name?: string|null, role_id: int} $data */
        $data = $request->validated();

        // authorizeUsersAccess above guarantees $actor (and a fortiori
        // $actor->tenant) is not null on the path that reaches here.
        $actor = $request->user();
        assert($actor !== null);
        $tenant = $actor->tenant;
        assert($tenant !== null);

        $lifetimeDays = (int) config('identity.invitation_lifetime_days', 7);

        $result = $action->execute(
            tenant: $tenant,
            email: $data['email'],
            name: $data['name'] ?? null,
            roleId: $data['role_id'],
            invitedByUserId: (int) $actor->id,
            lifetimeDays: $lifetimeDays,
        );

        // Raw token NEVER returned to the admin caller — UserInvited
        // event ships it to the queued email listener. Per §10.14
        // (one-time-secret display lifecycle).
        return (new AdminInvitationResource($result->invitation))
            ->response()
            ->setStatusCode(201);
    }
}
