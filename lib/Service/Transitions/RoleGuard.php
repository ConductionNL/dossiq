<?php

/**
 * Procest Role Guard evaluator.
 *
 * Guard config shape: `{type: 'roleGuard', allowedRoles: ['Behandelaar', 'Afdelingshoofd']}`.
 * Verifies the current user has at least one of the allowed roles on the
 * case. Failure is reported with `details.silent: true` so the UI can hide
 * the transition entirely (REQ-STE-2-003).
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transitions;

use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guard: verifies the current user has at least one allowed role on the case.
 *
 * Role lookup strategy (in order):
 *  1. `case.roles[]` entries with `{userId, role}`.
 *  2. Membership in a Nextcloud group named after the role (lowercase).
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T05
 */
class RoleGuard implements GuardEvaluatorInterface
{
    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager Nextcloud group manager
     * @param IUserManager    $userManager  Nextcloud user manager
     * @param LoggerInterface $logger       Logger
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Evaluate the role guard.
     *
     * @param array<string, mixed> $guardConfig Guard configuration
     * @param array<string, mixed> $case        Case object
     * @param string               $userId      Current user UID (from IUserSession by the caller)
     *
     * @return GuardResult

     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function evaluate(array $guardConfig, array $case, string $userId): GuardResult
    {
        $allowed = $guardConfig['allowedRoles'] ?? [];
        if (is_array($allowed) === false || count($allowed) === 0) {
            // No restriction means everyone passes.
            return new GuardResult(passed: true);
        }

        if ($userId === '') {
            return new GuardResult(passed: false, failureMessage: 'Niet ingelogd', details: ['silent' => true]);
        }

        // 1. Direct role assignment on case.roles[].
        $directRole = $this->matchCaseRole(case: $case, userId: $userId, allowed: $allowed);
        if ($directRole !== null) {
            return new GuardResult(passed: true, details: ['matchedRole' => $directRole]);
        }

        // 2. Fallback: Nextcloud group membership.
        $groupRole = $this->matchGroupRole(userId: $userId, allowed: $allowed);
        if ($groupRole !== null) {
            return new GuardResult(passed: true, details: ['matchedRole' => $groupRole, 'via' => 'group']);
        }

        // Role mismatch — silent so the UI hides the transition entirely.
        return new GuardResult(
            passed: false,
            failureMessage: 'Onvoldoende rechten',
            details: ['silent' => true, 'allowedRoles' => array_values($allowed)],
        );
    }//end evaluate()

    /**
     * Find an allowed role assigned to the user directly on the case.
     *
     * @param array<string, mixed> $case    Case object
     * @param string               $userId  Current user UID
     * @param array<int, mixed>    $allowed Allowed role identifiers
     *
     * @return string|null The matched role, or null when no entry matches
     */
    private function matchCaseRole(array $case, string $userId, array $allowed): ?string
    {
        $caseRoles = $case['roles'] ?? ($case['participants'] ?? []);
        if (is_array($caseRoles) === false) {
            return null;
        }

        foreach ($caseRoles as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $entryUser = (string) ($entry['userId'] ?? ($entry['user'] ?? ''));
            if ($entryUser !== $userId) {
                continue;
            }

            $entryRole = (string) ($entry['role'] ?? ($entry['roleType'] ?? ''));
            if (in_array($entryRole, $allowed, true) === true) {
                return $entryRole;
            }
        }//end foreach

        return null;
    }//end matchCaseRole()

    /**
     * Find an allowed role the user holds through Nextcloud group membership.
     *
     * A lookup failure is logged and treated as "no match" so the guard stays
     * closed rather than throwing out of the transition.
     *
     * @param string            $userId  Current user UID
     * @param array<int, mixed> $allowed Allowed role identifiers
     *
     * @return string|null The matched role, or null when no group matches
     */
    private function matchGroupRole(string $userId, array $allowed): ?string
    {
        try {
            $user = $this->userManager->get($userId);
            if ($user === null) {
                return null;
            }

            foreach ($allowed as $role) {
                $groupId = strtolower((string) $role);
                if ($groupId === '') {
                    continue;
                }

                if ($this->groupManager->isInGroup($userId, $groupId) === true) {
                    return (string) $role;
                }
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error('RoleGuard: group lookup failed', ['exception' => $e->getMessage()]);
        }//end try

        return null;
    }//end matchGroupRole()
}//end class
