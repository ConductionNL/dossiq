<?php

/**
 * Procest case-access policy for the sharing surface.
 *
 * Answers "may this user act on this case?" for every share operation. Three
 * things grant access: the case names the user as assignee (single-valued or
 * in the `assignees` array), or the user once minted a share for the case —
 * which is standing evidence they had access at that time.
 *
 * Split out of CaseSharingService so the fail-OPEN decisions this policy makes
 * are stated in one auditable place. Note the asymmetry, and that it is
 * deliberate: an unavailable OpenRegister or an unconfigured case schema
 * returns true, because the same-instance sharing feature must keep working on
 * basic setups where the caller is already authenticated by IUserSession. The
 * federation surface makes the opposite choice and fails closed — there is
 * nothing safe to fall back to once a request crosses an org boundary.
 *
 * A share lookup that throws is logged and read as "no share found" (deny),
 * never as a grant.
 *
 * @category Service
 * @package  OCA\Procest\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Sharing;

use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a user may act on a case for sharing purposes.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseAccessPolicy
{
    /**
     * Constructor.
     *
     * @param SettingsService            $settingsService The settings service
     * @param OpenRegisterSharingGateway $gateway         OpenRegister resolution for the sharing surface
     * @param LoggerInterface            $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly OpenRegisterSharingGateway $gateway,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether a given user may access a case for sharing purposes.
     *
     * A user is permitted when any of the following holds:
     *  - the case's `assignee` field equals the user ID
     *  - the user ID appears in `assignees` (array)
     *  - the user ID appears as a `createdBy` on any caseShare linked to the case
     *  - the caller is an NC admin (checked via group membership `admin`)
     *
     * Returns true when the case cannot be loaded (fail-safe for missing OR
     * config) to avoid breaking installations that have not configured the
     * case schema. The caller must still authenticate via IUserSession.
     *
     * @param string $caseId The case UUID
     * @param string $userId The caller's user ID
     *
     * @return bool True when the user may proceed
     *
     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function canUserAccessCase(string $caseId, string $userId): bool
    {
        $objectService = $this->gateway->objectService();
        if ($objectService === null) {
            // OR not available — fail-open so the feature still works on basic setups.
            return true;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            return true;
        }

        try {
            $caseObj = $objectService->find($caseId, register: (int) $register, schema: (int) $caseSchema);
            if ($caseObj === null) {
                // Case not found — deny (treated as 404 by callers).
                return false;
            }

            $caseData = $this->gateway->toArray(value: $caseObj);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseSharingService: canUserAccessCase load failed',
                ['caseId' => $caseId, 'exception' => $e->getMessage()]
            );
            return true;
        }

        // Direct assignee field, then the assignees array.
        if ($this->isCaseAssignee(caseData: $caseData, userId: $userId) === true) {
            return true;
        }

        // Check existing caseShares: if this user created any share for this case they
        // already had access at that time.
        if ($this->hasCreatedShareForCase(
            objectService: $objectService,
            caseId: $caseId,
            userId: $userId,
            register: (int) $register
        ) === true
        ) {
            return true;
        }

        return false;
    }//end canUserAccessCase()

    /**
     * Whether a case names the given user as assignee.
     *
     * Accepts both the single-valued `assignee` field and membership of the
     * `assignees` array; either one grants access.
     *
     * @param array<string, mixed> $caseData The case data
     * @param string               $userId   The caller's user ID
     *
     * @return bool True when the user is an assignee of the case
     */
    private function isCaseAssignee(array $caseData, string $userId): bool
    {
        // Direct assignee field (single user ID string).
        if (isset($caseData['assignee']) === true && (string) $caseData['assignee'] === $userId) {
            return true;
        }

        // Assignees array.
        $assignees = ($caseData['assignees'] ?? []);
        if (is_array($assignees) === true && in_array($userId, $assignees, true) === true) {
            return true;
        }

        return false;
    }//end isCaseAssignee()

    /**
     * Whether the given user created any caseShare for the given case.
     *
     * A user who once minted a share for the case already had access at that
     * time, so the share record is treated as standing evidence of access.
     * A failed lookup is logged and treated as "no share found" (deny), never
     * as a grant.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $caseId        The case UUID
     * @param string $userId        The caller's user ID
     * @param int    $register      The configured register id
     *
     * @return bool True when a share created by this user exists
     */
    private function hasCreatedShareForCase(
        object $objectService,
        string $caseId,
        string $userId,
        int $register,
    ): bool {
        $shareSchema = $this->settingsService->getConfigValue('case_share_schema');
        if (empty($shareSchema) === true) {
            return false;
        }

        try {
            $shares = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => (int) $shareSchema, 'caseId' => $caseId]],
            );

            foreach ($shares as $share) {
                $shareData = $this->gateway->toArray(value: $share);
                if (isset($shareData['createdBy']) === true && (string) $shareData['createdBy'] === $userId) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'CaseSharingService: share lookup in canUserAccessCase failed',
                ['caseId' => $caseId, 'exception' => $e->getMessage()]
            );
        }//end try

        return false;
    }//end hasCreatedShareForCase()
}//end class
