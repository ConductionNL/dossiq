<?php

/**
 * Procest Citizen Lookup Guard.
 *
 * Role authorization for the endpoints that resolve a raw citizen identifier
 * (BSN / burgerId) into that citizen's case and contact history.
 *
 * These endpoints have no per-object owner to check against: the subject is a
 * citizen, not a case, and a KCC agent legitimately answers a call from a
 * citizen they have never handled before. `CaseAccessGuard` therefore does not
 * apply — the question is not "does this user handle this case?" but "is this
 * user a klantcontactcentrum handler at all?".
 *
 * Before this guard existed, `GET /api/kcc/voorblad?burgerId=…` answered HTTP
 * 200 with the citizen's open cases and recent contact history — including the
 * caller's phone number and the free-text summary of every previous call — to
 * ANY authenticated account on the instance. Iterating BSN-shaped identifiers
 * walked the resident population. Reproduced live with two accounts before this
 * change (finding PROC-IDOR-01).
 *
 * Fails closed, and deliberately in the opposite direction to the bug
 * `CaseAccessGuard` was written for: there, a group that did not exist made
 * `groupExists() && !isInGroup()` short-circuit to "authorized". Here the
 * absence of every listed group grants nothing — only an actual membership, or
 * Nextcloud admin, passes.
 *
 * The group list mirrors the idiom already used for the other broad-scope reads
 * in this app (`ParaferingAuditExportController::ALLOWED_GROUPS`,
 * `AiAuditExportController`, `ProcessMiningController`): a fixed set of
 * deployment group names plus an admin fallback.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\IGroupManager;
use OCP\IUser;
use Throwable;

/**
 * Guards citizen-identifier lookups against the caller's KCC role.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class CitizenLookupGuard
{

    /**
     * Groups whose members may resolve a citizen identifier.
     *
     * An empty intersection denies. The existence of these groups is never
     * part of the decision — a group that does not exist simply matches
     * nobody.
     */
    private const ALLOWED_GROUPS = ['kcc', 'klantcontact', 'beheerders', 'admin'];

    /**
     * Constructor.
     *
     * @param IGroupManager $groupManager The group manager.
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Whether the given user may resolve citizen identifiers.
     *
     * @param IUser $user The authenticated user.
     *
     * @return bool True when the user is a KCC handler or a Nextcloud admin.
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function isCitizenLookupAllowed(IUser $user): bool
    {
        $uid = $user->getUID();
        if ($uid === '') {
            return false;
        }

        try {
            foreach (self::ALLOWED_GROUPS as $group) {
                if ($this->groupManager->isInGroup($uid, $group) === true) {
                    return true;
                }
            }

            return $this->groupManager->isAdmin($uid);
        } catch (Throwable $e) {
            // An unresolvable group check is not an authorization.
            return false;
        }
    }//end isCitizenLookupAllowed()
}//end class
