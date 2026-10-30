<?php

/**
 * Dossiq handler acts as the system.
 *
 * Some acts on a case write to another app's register the handler has no
 * rights on: publishing a decision creates a publication in OpenCatalogi,
 * and the handler holds no create on that schema, so the publish failed with
 * a 503. The decision to publish is the handler's; the write is dossiq's.
 *
 * So the act runs as the system, but only after two checks on THIS case:
 * the caller is its handler (has claimed it; an administrator also passes),
 * and the case is at the step the act belongs to: not closed, and the named
 * field holds one of the values the step allows. A refused check is a
 * RefusedException (403 or 422), never an elevated write and never a 500.
 *
 * The same scoped elevation as DocumentProjectionService::asSystem (#3965):
 * OpenRegister's runAsSystem, entered only after the caller is checked.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IUser;

/**
 * Runs one act on a case as the system, for its handler, at its step.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class HandlerActsAsSystem {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param CaseAccessGuard $guard    Whether the caller is the case's handler.
	 * @param CaseStatusStore $store    Reads the case.
	 * @param SettingsService $settings The OpenRegister object service.
	 */
	public function __construct(
		private readonly CaseAccessGuard $guard,
		private readonly CaseStatusStore $store,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Check the caller and the step, then run the act as the system.
	 *
	 * @param string             $caseId    The case UUID.
	 * @param IUser              $user      The caller.
	 * @param string             $stepField The case field that says the act's step is reached.
	 * @param array<int, string> $stepIn    The values of that field the act is allowed at.
	 * @param callable           $operation The act.
	 *
	 * @return mixed What the act returns.
	 *
	 * @throws RefusedException When the caller is not the handler (403) or the case
	 *                          is not at the act's step (422).
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function run(string $caseId, IUser $user, string $stepField, array $stepIn, callable $operation): mixed {
		if ($this->guard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			throw new RefusedException(
				rule: 'not-the-handler',
				sentence: 'Claim this case first: only its handler can do this.',
				status: RefusedException::STATUS_FORBIDDEN,
			);
		}

		$case = $this->store->loadCase(caseId: $caseId);
		$atStep = ($case !== null
			&& in_array(($case['isFinalStatus'] ?? false), [true, 1, '1', 'true'], true) === false
			&& in_array((string)($case[$stepField] ?? ''), $stepIn, true) === true);
		if ($atStep === false) {
			throw new RefusedException(
				rule: 'not-at-this-step',
				sentence: 'This case is not at the step where this can be done.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return $operation();
		}

		return $this->runAsSystemIfAvailable(objectService: $objectService, operation: $operation);
	}//end run()
}//end class
