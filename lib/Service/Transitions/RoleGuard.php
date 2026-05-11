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

use OCA\Procest\Service\SettingsService;
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
     * @param SettingsService $settingsService Bridge to OpenRegister + config
     * @param IGroupManager   $groupManager    Nextcloud group manager
     * @param IUserManager    $userManager     Nextcloud user manager
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
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
     */
    public function evaluate(array $guardConfig, array $case, string $userId): GuardResult
    {
        $allowed = $guardConfig['allowedRoles'] ?? [];
        if (is_array($allowed) === false || count($allowed) === 0) {
            // No restriction means everyone passes.
            return GuardResult::pass();
        }

        if ($userId === '') {
            return GuardResult::fail(message: 'Niet ingelogd', details: ['silent' => true]);
        }

        // 1. Direct role assignment on case.roles[].
        $caseRoles = $case['roles'] ?? ($case['participants'] ?? []);
        if (is_array($caseRoles) === true) {
            foreach ($caseRoles as $entry) {
                if (is_array($entry) === false) {
                    continue;
                }
                $entryUser = (string) ($entry['userId'] ?? ($entry['user'] ?? ''));
                $entryRole = (string) ($entry['role'] ?? ($entry['roleType'] ?? ''));
                if ($entryUser === $userId && in_array($entryRole, $allowed, true) === true) {
                    return GuardResult::pass(details: ['matchedRole' => $entryRole]);
                }
            }
        }

        // 2. Fallback: Nextcloud group membership.
        try {
            $user = $this->userManager->get($userId);
            if ($user !== null) {
                foreach ($allowed as $role) {
                    $groupId = strtolower((string) $role);
                    if ($groupId === '') {
                        continue;
                    }
                    if ($this->groupManager->isInGroup($userId, $groupId) === true) {
                        return GuardResult::pass(details: ['matchedRole' => $role, 'via' => 'group']);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('RoleGuard: group lookup failed', ['exception' => $e->getMessage()]);
        }

        // Role mismatch — silent so the UI hides the transition entirely.
        return GuardResult::fail(
            message: 'Onvoldoende rechten',
            details: ['silent' => true, 'allowedRoles' => array_values($allowed)],
        );
    }//end evaluate()
}//end class
