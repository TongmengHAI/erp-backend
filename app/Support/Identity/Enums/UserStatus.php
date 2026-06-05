<?php

declare(strict_types=1);

namespace App\Support\Identity\Enums;

/**
 * User lifecycle state. Orthogonal to UserType (super_admin vs tenant_user)
 * and to soft-delete (deleted_at).
 *
 *   Active   — default. Can authenticate. Normal operational state.
 *   Inactive — soft-blocked. Cannot authenticate. Reversible via Enable.
 *              Semantic: "temporarily blocked" (employee on extended
 *              leave, suspended access pending HR review, etc.).
 *
 * For HARD removal use soft-delete (SoftDeletes trait on User → deleted_at).
 * The two mechanisms compose: a user that is both inactive AND
 * soft-deleted is rejected by either gate; LoginController's predicate
 * checks both independently per §10.17 (split, not relax).
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring these
 * values (see 2026_06_05_100100_add_status_and_softdeletes_to_users_table.php).
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
