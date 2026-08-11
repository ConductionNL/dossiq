<?php

/**
 * Procest Case Access Guard.
 *
 * Per-case mutation authorization that FAILS CLOSED.
 *
 * Replaces `WOOAssessmentController::requireCaseMutationAccess()`'s group check,
 * which read:
 *
 *   if (groupExists('procest-gebruikers') === true
 *       && isInGroup($uid, 'procest-gebruikers') === false) { throw; }
 *
 * `procest-gebruikers` was referenced nowhere else in the codebase and never
 * created, so `groupExists()` returned false, the `&&` short-circuited, no
 * exception was thrown, and EVERY authenticated user was authorized on all five
 * `#[NoAdminRequired]` WOO mutation endpoints — including statutory deadline
 * extension. Creating the group would not have fixed it: the check was
 * group-shaped, so any case worker could still mutate any case.
 *
 * This guard removes group existence from the authorization decision entirely
 * and asks the only question that matters: does this user actually handle this
 * case? It mirrors the already-live, already-fail-closed
 * `DsoCaseService::authorizeZaakMutation()` rather than inventing a new idiom.
 *
 * ADR-022: OpenRegister owns RBAC. Procest does not grow a parallel RBAC
 * engine — the case is resolved THROUGH OR's `ObjectService` (so OR's own
 * access rules apply to that read) and this class only enforces the per-case
 * relationship on top.
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

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards mutations of a case against the caller's relationship to that case.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class CaseAccessGuard
{

    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service (OR access).
     * @param IGroupManager   $groupManager    Group manager (admin check only).
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assert that the given user may mutate the given case.
     *
     * Decision table (fails closed at every branch):
     *   - admin                  -> allow
     *   - OpenRegister absent    -> DENY (never "skip the check")
     *   - case not resolvable    -> DENY (collapsed with denied: no existence oracle)
     *   - uid === case.assignee  -> allow
     *   - otherwise              -> DENY
     *
     * Group existence plays no part. The absence of any group can never grant
     * access.
     *
     * @param string $caseId The case UUID.
     * @param IUser  $user   The authenticated user.
     *
     * @return void
     *
     * @throws OCSForbiddenException When the user may not mutate this case.
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function assertCaseMutationAccess(string $caseId, IUser $user): void
    {
        if ($this->hasCaseMutationAccess(caseId: $caseId, user: $user) === true) {
            return;
        }

        throw new OCSForbiddenException('Not authorized to modify case '.$caseId);
    }//end assertCaseMutationAccess()

    /**
     * Whether the given user may mutate the given case.
     *
     * @param string $caseId The case UUID.
     * @param IUser  $user   The authenticated user.
     *
     * @return bool True when the user handles the case or is an admin.
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function hasCaseMutationAccess(string $caseId, IUser $user): bool
    {
        $uid = $user->getUID();
        if ($uid === '' || $caseId === '') {
            return false;
        }

        // Admins bypass per-case checks (consistent with DsoCaseService and
        // AdviceService).
        try {
            if ($this->groupManager->isAdmin($uid) === true) {
                return true;
            }
        } catch (Throwable $e) {
            // An unresolvable admin check is NOT an authorization: fall through
            // to the per-case check rather than granting or throwing.
            $this->logger->warning(
                'Procest CaseAccessGuard: admin check failed: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }

        $case = $this->loadCase(caseId: $caseId);
        if ($case === null) {
            // OR unavailable / not configured / case missing / read denied by
            // OR's own RBAC — all deny. Never proceed unchecked.
            return false;
        }

        $assignee = (string) ($case['assignee'] ?? '');

        return ($assignee !== '' && $assignee === $uid);
    }//end hasCaseMutationAccess()

    /**
     * Whether the given user may read the given case.
     *
     * Reads are granted to a slightly wider set than mutations, because a case
     * is worked on by more people than the one named in `assignee`: the
     * `assignees` array is honoured as well. It is still a real per-case
     * relationship, and it still fails closed at every branch — an
     * unresolvable case, an absent OpenRegister, or an unconfigured schema all
     * DENY.
     *
     * Deliberately NOT delegated to
     * {@see Sharing\CaseAccessPolicy::canUserAccessCase()}: that one returns
     * TRUE when OpenRegister is absent, when the schema is unconfigured, and
     * when the lookup throws. Those three fail-OPEN branches are acceptable for
     * the sharing UI it was written for and are not acceptable here.
     *
     * @param string $caseId The case UUID.
     * @param IUser  $user   The authenticated user.
     *
     * @return bool True when the user works on the case or is an admin.
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function hasCaseReadAccess(string $caseId, IUser $user): bool
    {
        $uid = $user->getUID();
        if ($uid === '' || $caseId === '') {
            return false;
        }

        try {
            if ($this->groupManager->isAdmin($uid) === true) {
                return true;
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest CaseAccessGuard: admin check failed: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }

        $case = $this->loadCase(caseId: $caseId);
        if ($case === null) {
            return false;
        }

        if ((string) ($case['assignee'] ?? '') === $uid) {
            return true;
        }

        $assignees = ($case['assignees'] ?? []);

        return (is_array($assignees) === true && in_array($uid, $assignees, true) === true);
    }//end hasCaseReadAccess()

    /**
     * Load a case through OpenRegister.
     *
     * @param string $caseId The case UUID.
     *
     * @return array<string, mixed>|null The case, or null when unresolvable.
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    private function loadCase(string $caseId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $this->logger->warning(
                'Procest CaseAccessGuard: OpenRegister unavailable — denying case mutation',
                ['app' => Application::APP_ID]
            );
            return null;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');
        if (empty($register) === true || empty($caseSchema) === true) {
            $this->logger->warning(
                'Procest CaseAccessGuard: case schema not configured — denying case mutation',
                ['app' => Application::APP_ID]
            );
            return null;
        }

        try {
            return $this->findObjectAsArray(
                objectService: $objectService,
                register: $register,
                schema: $caseSchema,
                id: $caseId
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest CaseAccessGuard: case lookup failed — denying case mutation: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return null;
        }
    }//end loadCase()
}//end class
