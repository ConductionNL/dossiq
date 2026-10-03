<?php

/**
 * Dossiq Case Access Guard.
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
 * ADR-022: OpenRegister owns RBAC. Dossiq does not grow a parallel RBAC
 * engine — the case is resolved THROUGH OR's `ObjectService` (so OR's own
 * access rules apply to that read) and this class only enforces the per-case
 * relationship on top.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
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
class CaseAccessGuard {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (OR access).
	 * @param IGroupManager $groupManager Group manager (admin check only).
	 * @param LoggerInterface $logger The logger.
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
	 * @param IUser $user The authenticated user.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the user may not mutate this case.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function assertCaseMutationAccess(string $caseId, IUser $user): void {
		if ($this->hasCaseMutationAccess(caseId: $caseId, user: $user) === true) {
			return;
		}

		throw new OCSForbiddenException('Not authorized to modify case ' . $caseId);
	}//end assertCaseMutationAccess()

	/**
	 * Whether the given user may mutate the given case.
	 *
	 * 🔴 THE VERB DOES NOT WIDEN, AND NOW IT DOES NOT WIDEN ACROSS AN APP
	 * BOUNDARY EITHER (row Q13.23, D-3). A read on a parent reaches its
	 * deelzaken; a right to see a case is not a right to change its children.
	 * What pins it is the ABSENCE of a call to
	 * {@see self::holdsPlatformGrant()} here: that is where inheritance
	 * arrives now, so a mutation path that consulted it would inherit the
	 * write the whole rule exists to refuse. Anything added to this method
	 * that asks the platform, or that resolves an ancestor, is the
	 * regression.
	 *
	 * @param string $caseId The case UUID.
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when the user handles the case itself or is an admin.
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function hasCaseMutationAccess(string $caseId, IUser $user): bool {
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
				'Dossiq CaseAccessGuard: admin check failed: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
		}

		$case = $this->loadCase(caseId: $caseId);
		if ($case === null) {
			// OR unavailable / not configured / case missing / read denied by
			// OR's own RBAC — all deny. Never proceed unchecked.
			return false;
		}

		$assignee = (string)($case['assignee'] ?? '');

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
	 * A READ REACHES A DEELZAAK FROM ITS PARENT, AND DOSSIQ NO LONGER WALKS
	 * FOR IT. openregister#3873 resolves a per-object grant over the declared
	 * `x-openregister-hierarchy` edge, so a grant on a parent case answers for
	 * its deelzaken, in the layer that owns grants. This guard asked the same
	 * question a second time, over the same edge, in its own loop; two
	 * resolvers of one question is what ADR-022 refuses and what D-2 of
	 * `deelzaken-inherit-the-parent-grants` said would go the moment the
	 * platform could answer. It can, so it has.
	 *
	 * WHAT IS LEFT IS TWO WAYS IN, AND NEITHER IS THE SCHEMA'S OWN READ RULE.
	 *
	 *  - the per-case RELATIONSHIP: the caller is named on the case, as its
	 *    assignee or in `assignees`. dossiq's own rule, unchanged since the
	 *    gate-7 remediation, and the reason this guard exists at all;
	 *  - a per-object GRANT, which OpenRegister resolves and which now
	 *    includes an inherited one.
	 *
	 * 🔴 IT DOES NOT DEFER WHOLESALE TO `loadCase()` RESOLVING. That was the
	 * tempting shape and it is a silent widening: `ObjectService::find()`
	 * applies the SCHEMA's read rule, and a case schema whose rule is
	 * `authenticated` resolves every case for every logged-in user. Deferring
	 * would have turned this guard into a check that every authenticated user
	 * passes, on an instance where nothing looked different afterwards. A
	 * grant is a deliberate invitation; a schema rule is not.
	 *
	 * @param string $caseId The case UUID.
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when the user works on the case, holds a grant on it, or is an admin.
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function hasCaseReadAccess(string $caseId, IUser $user): bool {
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
				'Dossiq CaseAccessGuard: admin check failed: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
		}

		// The platform is asked FIRST and without loading the case, because a
		// grant is an answer about this caller and this object that needs no
		// case payload. It is also the branch that carries the inheritance, so
		// keeping it ahead of the load means a deelzaak reached through its
		// parent costs one question rather than a read plus a question.
		if ($this->holdsPlatformGrant(caseId: $caseId, uid: $uid) === true) {
			return true;
		}

		$case = $this->loadCase(caseId: $caseId);
		if ($case === null) {
			return false;
		}

		if ((string)($case['assignee'] ?? '') === $uid) {
			return true;
		}

		$assignees = ($case['assignees'] ?? []);

		return (is_array($assignees) === true && in_array($uid, $assignees, true) === true);
	}//end hasCaseReadAccess()

	/**
	 * Whether OpenRegister says this caller holds a read grant on this case.
	 *
	 * The consuming half of openregister#3873. A grant written on an ancestor
	 * answers here for a descendant, because the resolver expands the grant set
	 * over the declared hierarchy before anything asks it a question; dossiq
	 * declares the edge on its `case` schema and reads the answer.
	 *
	 * WHY THE CONTAINER LOOKUP GETS THE RIGHT INSTANCE, which is load-bearing
	 * and not obvious. `ServerContainer::getAppContainerForService()` reads the
	 * namespace off the class name and routes an `OCA\OpenRegister\…` lookup
	 * to OPENREGISTER's own container, where that app registers this resolver
	 * explicitly with its hierarchy expander wired in. Had the lookup been
	 * autowired in dossiq's container instead, the expander is a NULLABLE
	 * constructor argument, so what came back would answer about direct grants
	 * only — a deelzaak reached through its parent would be refused, with no
	 * error and nothing to see.
	 *
	 * ABSENT MEANS NOT GRANTED. A missing app, a container that cannot resolve
	 * the class, an older OpenRegister without the method, a resolver that
	 * throws: every one of them answers false. That is fail-closed, and it is
	 * also exactly the behaviour dossiq had before any of this existed, so an
	 * instance that cannot ask loses inheritance rather than gaining access.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $uid The caller.
	 *
	 * @return bool True only when the platform affirmatively says so.
	 *
	 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
	 */
	private function holdsPlatformGrant(string $caseId, string $uid): bool {
		$resolver = $this->settingsService->getObjectGrantResolver();
		if ($resolver === null || method_exists($resolver, 'isGranted') === false) {
			return false;
		}

		try {
			return ($resolver->isGranted($uid, $caseId, 'read') === true);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq CaseAccessGuard: the platform grant lookup failed, treating the caller as holding none: '
				. $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return false;
		}
	}//end holdsPlatformGrant()

	/**
	 * Load a case through OpenRegister.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The case, or null when unresolvable.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function loadCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$this->logger->warning(
				'Dossiq CaseAccessGuard: OpenRegister unavailable — denying case mutation',
				['app' => Application::APP_ID]
			);
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if (empty($register) === true || empty($caseSchema) === true) {
			$this->logger->warning(
				'Dossiq CaseAccessGuard: case schema not configured — denying case mutation',
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
				'Dossiq CaseAccessGuard: case lookup failed — denying case mutation: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return null;
		}
	}//end loadCase()
}//end class
