<?php

/**
 * Run a flow step's storage work as the run's acting identity.
 *
 * WHY THIS EXISTS. OpenRegister's engine hands every node the run's acting
 * identity in the context (`runAs`), and its own write-capable nodes wrap
 * their storage calls in `ObjectService::runAs()` — because the permission
 * gate (MagicRbacHandler / MagicOrganizationHandler) reads the AMBIENT SESSION
 * user, not any parameter. Under FlowRunWorker (a cron process) that session
 * carries nobody, so a bare `saveObject()` is refused as "User 'Anonymous'
 * does not have permission" no matter whose rights the run declares. Measured
 * live on dossiq 0.3.11: the seeded "Case behandeling" flow could not move a
 * single case because every dossiq-owned write ignored the `runAs` the
 * context carried.
 *
 * 🔴 IT NARROWS, NEVER GRANTS. `runAs()` sets the session subject to a NAMED
 * user for the duration of the callable; a run whose owner cannot write is
 * still refused, and now for the right reason. This is deliberately not
 * `runAsSystem()`: a flow node is user-authored input, which is exactly the
 * caller ADR-099 forbids from reaching the userless principal.
 *
 * WHEN THE CONTEXT NAMES NOBODY the operation runs bare, under whatever
 * session is ambient. That is the interactive path: dossiq's transition
 * handlers also fire from SideEffectDispatcher inside a real request, where
 * the logged-in user IS the acting identity and no `runAs` key exists.
 *
 * WHEN THE CONTEXT NAMES SOMEBODY UNUSABLE it refuses loudly. A `runAs` that
 * resolves to no account, to a disabled account, or arrives while the object
 * service cannot scope identities is a run that must stop, not a write that
 * silently happens as someone else — the same refusal shape OpenRegister's
 * ObjectWriteNode uses, including the disabled check, so a parked run never
 * resumes with the rights of somebody who has since been offboarded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\IUserManager;
use RuntimeException;

/**
 * Scopes a flow step's storage work to the run's `runAs` identity.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */
class FlowRunAsScope {

	/**
	 * The context key OpenRegister's FlowRunService stamps the acting identity under.
	 *
	 * A plain string by the engine's contract (`$context['runAs'] =
	 * $run->getRunAs()`); the engine exports no constant for it.
	 *
	 * @var string
	 */
	public const CONTEXT_RUN_AS = 'runAs';


	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the object service that owns the runAs seam.
	 * @param IUserManager    $userManager     Resolves the named identity to a real account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()


	/**
	 * Run the operation as the context's acting identity, when it names one.
	 *
	 * @param array    $context   The flow run context, possibly carrying `runAs`.
	 * @param callable $operation The storage work to perform.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @throws RuntimeException When the named identity cannot be acted as.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function call(array $context, callable $operation): mixed {
		$uid = trim((string) ($context[self::CONTEXT_RUN_AS] ?? ''));
		if ($uid === '') {
			// No acting identity declared: the interactive path, where the
			// ambient session user already answers the permission checks.
			return $operation();
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new RuntimeException(
				sprintf('This flow run\'s acting identity "%s" (runAs) is not a user account; the step is refused.', $uid)
			);
		}

		// A disabled account still resolves. Rights are re-checked at the
		// moment work runs so that disabling an account — the most common
		// revocation there is — takes effect on a run parked for weeks.
		if ($user->isEnabled() === false) {
			throw new RuntimeException(
				sprintf('This flow run\'s acting identity "%s" (runAs) is a disabled account; the step is refused.', $uid)
			);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null || method_exists($objectService, 'runAs') === false) {
			// The run DECLARES an identity this installation cannot honour.
			// Running bare instead would perform the write as whoever the
			// ambient session happens to carry — under a worker, nobody — so
			// the honest outcome is a refusal naming what is missing.
			throw new RuntimeException(
				'This flow run names an acting identity (runAs), but the object service cannot scope one; the step is refused.'
			);
		}

		return $objectService->runAs($user, $operation);
	}//end call()
}//end class
