<?php

/**
 * Procest Advice Authorization Guard.
 *
 * The procest#17 / Wilco #6 per-object IDOR guard for advice-request
 * status transitions. Split out of AdviceService so that service keeps
 * only the workflow orchestration: the entire question "may THIS caller
 * move THIS advice request to THAT status" — the caller's relationship
 * to the advice (adviseur), the relationship to the linked case
 * (assignee), the admin bypass, and the fail-closed default — lives here
 * and nowhere else.
 *
 * Identity is ALWAYS derived from IUserSession; a caller-supplied user id
 * is never trusted. The guard fails CLOSED: an unknown target status, an
 * unresolvable case, or a missing session all deny.
 *
 * Matrix (admins bypass all per-object checks):
 *   - ontvangen:   the assigned `adviseur` only. This is the IDOR that
 *                  was open on transitionStatus() — previously ANY
 *                  authenticated user could mark ANY advice request as
 *                  received.
 *   - aangevraagd: the handler of the linked case, or the `adviseur`.
 *   - verlopen:    nobody. Expiry is a system transition owned by
 *                  AdviceDeadlineJob, which reaches the write without
 *                  passing through this guard.
 *   - default:     denied.
 *
 * @category Service
 * @package  OCA\Procest\Service\Advice
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Advice;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\IGroupManager;
use OCP\IUserSession;
use RuntimeException;

/**
 * Authorizes advice-request status transitions against the caller.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class AdviceAuthorizationGuard {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config + ObjectService bridge.
	 * @param IUserSession $userSession The current user session.
	 * @param IGroupManager $groupManager Group manager (admin bypass).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Authorize an advice status transition against the CALLER's
	 * relationship to the advice request. Fails closed.
	 *
	 * @param array<string, mixed> $advice The current advice record.
	 * @param string $to Target status.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the caller is not authenticated or not authorized.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function assertTransitionAuthorized(array $advice, string $to): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('Not authenticated');
		}

		$uid = $user->getUID();

		if ($this->groupManager->isAdmin($uid) === true) {
			return;
		}

		if ($this->mayTransition(advice: $advice, to: $to, uid: $uid) === true) {
			return;
		}

		throw new RuntimeException('Advice request not accessible');
	}//end assertTransitionAuthorized()

	/**
	 * Assert that the caller may dispatch a manual reminder for this advice.
	 *
	 * `POST /api/advice/{id}/remind` sends a notification to the adviseur named
	 * on an advice record the caller picks by UUID. It had no guard at all,
	 * while `assertTransitionAuthorized()` — in this same class, reached from
	 * the same service — guarded the transition path. Anyone could spam any
	 * adviseur, and the response distinguished a real advice UUID from a
	 * fabricated one.
	 *
	 * Same relationship model as an `aangevraagd` transition: the adviseur
	 * themselves, the handler of the linked case, or an admin. Fails closed —
	 * an unauthenticated caller or an unresolvable case denies.
	 *
	 * @param array<string, mixed> $advice The current advice record.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the caller is not authenticated or not authorized.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function assertReminderAuthorized(array $advice): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('Not authenticated');
		}

		$uid = $user->getUID();

		if ($this->groupManager->isAdmin($uid) === true) {
			return;
		}

		$advisor = (string)($advice['advisor'] ?? '');
		if ($advisor !== '' && $advisor === $uid) {
			return;
		}

		if ($this->isHandlerOfLinkedCase(advice: $advice, uid: $uid) === true) {
			return;
		}

		throw new RuntimeException('Advice request not accessible');
	}//end assertReminderAuthorized()

	/**
	 * Whether a non-admin caller may perform the given advice transition.
	 *
	 * Returns false for `verlopen` (system-only) and for any unknown
	 * status — the default is deny.
	 *
	 * @param array<string, mixed> $advice The current advice record.
	 * @param string $to Target status.
	 * @param string $uid The caller's user id.
	 *
	 * @return bool True when the transition is allowed for this caller.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function mayTransition(array $advice, string $to, string $uid): bool {
		$advisor = (string)($advice['advisor'] ?? '');
		$isAdviseur = ($advisor !== '' && $advisor === $uid);

		if ($to === 'ontvangen') {
			return $isAdviseur;
		}

		if ($to === 'aangevraagd') {
			return ($isAdviseur === true || $this->isHandlerOfLinkedCase(advice: $advice, uid: $uid) === true);
		}

		return false;
	}//end mayTransition()

	/**
	 * Whether the given uid is the assignee of the case this advice belongs to.
	 *
	 * @param array<string, mixed> $advice The advice record.
	 * @param string $uid The caller's user id.
	 *
	 * @return bool True when the caller handles the linked case.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function isHandlerOfLinkedCase(array $advice, string $uid): bool {
		$caseId = (string)($advice['case'] ?? '');
		if ($caseId === '') {
			return false;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if (empty($register) === true || empty($caseSchema) === true) {
			return false;
		}

		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId
		);

		if ($case === null) {
			return false;
		}

		$assignee = (string)($case['assignee'] ?? '');

		return ($assignee !== '' && $assignee === $uid);
	}//end isHandlerOfLinkedCase()
}//end class
