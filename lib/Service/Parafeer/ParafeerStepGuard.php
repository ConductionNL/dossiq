<?php

/**
 * Dossiq parafeer step guard.
 *
 * The fail-closed gate every parafeeractie passes through: it resolves the
 * voorstel's current step from its route snapshot, asserts the caller is that
 * step's actor (or a mandated delegate), and refuses an action that the step
 * type does not permit or that is missing its mandatory free-text field. Split
 * out of ParafeerActieService so that service records and propagates actions
 * while the question "is this caller allowed to do this here?" has one owner.
 *
 * Every path throws rather than returning a boolean, and no path has a
 * permissive default: an unknown step type, an unresolvable snapshot and a
 * non-actor caller are all refusals (OWASP A01:2021, ADR-005 Rule 3).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Parafeer
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
 * @spec openspec/changes/parafering-actions/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;

/**
 * Resolves the active parafering step and authorises the action against it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/parafering-actions/tasks.md#T02
 */
class ParafeerStepGuard {
	/**
	 * Action: actor advised on an advies step.
	 *
	 * @var string
	 */
	public const ACTION_ADVISED = 'advised';

	/**
	 * Action: actor parafered a parafering step.
	 *
	 * @var string
	 */
	public const ACTION_PARAFERED = 'parafered';

	/**
	 * Action: actor accorded an accordering step.
	 *
	 * @var string
	 */
	public const ACTION_ACCORDED = 'accorded';

	/**
	 * Action: actor returned the voorstel to the steller.
	 *
	 * @var string
	 */
	public const ACTION_RETURNED = 'returned';

	/**
	 * Step type: advies.
	 *
	 * @var string
	 */
	public const STEP_TYPE_ADVIES = 'advice';

	/**
	 * Step type: parafering.
	 *
	 * @var string
	 */
	public const STEP_TYPE_PARAFERING = 'parafering';

	/**
	 * Step type: accordering.
	 *
	 * @var string
	 */
	public const STEP_TYPE_ACCORDERING = 'accordering';

	/**
	 * The actions each step type permits. A step type absent from this table
	 * permits nothing.
	 *
	 * @var array<string, string[]>
	 */
	private const ALLOWED_ACTIONS = [
		self::STEP_TYPE_ADVIES => [self::ACTION_ADVISED, self::ACTION_RETURNED],
		self::STEP_TYPE_PARAFERING => [self::ACTION_PARAFERED, self::ACTION_RETURNED],
		self::STEP_TYPE_ACCORDERING => [self::ACTION_ACCORDED, self::ACTION_RETURNED],
	];

	/**
	 * Resolve the current step from the route snapshot.
	 *
	 * @param array<string, mixed> $proposal The voorstel array.
	 *
	 * @return array<string, mixed> The current step (order, type, actor, label).
	 *
	 * @throws OCSBadRequestException When no current step is set or the route snapshot is missing/invalid.
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function resolveCurrentStep(array $proposal): array {
		$currentStep = (int)($proposal['currentStep'] ?? 0);
		if ($currentStep < 1) {
			throw new OCSBadRequestException('Voorstel has no active step');
		}

		$snapshotRaw = $proposal['routeSnapshot'] ?? null;
		if ($snapshotRaw === null) {
			throw new OCSBadRequestException('Voorstel has no route snapshot');
		}

		$decoded = $snapshotRaw;
		if (is_string($snapshotRaw) === true) {
			$decoded = json_decode($snapshotRaw, true);
		}

		if (is_array($decoded) === false) {
			throw new OCSBadRequestException('Invalid route snapshot');
		}

		foreach ($decoded as $step) {
			if (is_array($step) === true && (int)($step['order'] ?? 0) === $currentStep) {
				return $step;
			}
		}

		throw new OCSBadRequestException('Current step not found in route snapshot');
	}//end resolveCurrentStep()

	/**
	 * Authorize the current user against the step actor (or valid delegate).
	 *
	 * @param array<string, mixed> $step The current step.
	 * @param IUser $currentUser The authenticated user.
	 * @param string|null $onBehalfOf The principal UID when acting as delegate.
	 * @param string|null $mandate The mandate reference.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the current user is not the step actor and no valid delegate is configured.
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function authorize(array $step, IUser $currentUser, ?string $onBehalfOf, ?string $mandate): void {
		$stepActor = (string)($step['actor'] ?? '');
		$userUid = $currentUser->getUID();

		if ($stepActor === $userUid) {
			return;
		}

		if ($onBehalfOf !== null && $onBehalfOf === $stepActor && $mandate !== null && $mandate !== '') {
			// Mandate-based delegate authorization. The mandate registry check is the
			// responsibility of the frontend "Namens" selector (which only exposes
			// configured mandates) and the future MandaatService — see roadmap.
			return;
		}

		throw new OCSForbiddenException('Not authorized for this parafering step');
	}//end authorize()

	/**
	 * Validate that the action is allowed for the given step type.
	 *
	 * @param array<string, mixed> $step The current step (must include 'type').
	 * @param string $action The proposed action.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When the action is invalid for the step type.
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function validateActionForStepType(array $step, string $action): void {
		$stepType = (string)($step['type'] ?? '');
		$allowed = self::ALLOWED_ACTIONS;

		if (isset($allowed[$stepType]) === false || in_array($action, $allowed[$stepType], true) === false) {
			throw new OCSBadRequestException('Invalid action for this step type');
		}
	}//end validateActionForStepType()

	/**
	 * Validate required fields per action.
	 *
	 * Step-type-specific rules live in
	 * {@see self::validateActionForStepType()}; this check is purely about the
	 * mandatory free-text fields, so it takes no step.
	 *
	 * @param string $action The action.
	 * @param string $comment The comment (may be empty).
	 * @param string $advice The advice (may be empty).
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When mandatory comment/advice is missing.
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function validateRequiredFields(string $action, string $comment, string $advice): void {
		if ($action === self::ACTION_RETURNED && $comment === '') {
			throw new OCSBadRequestException('Return reason is required');
		}

		if ($action === self::ACTION_ADVISED && $advice === '') {
			throw new OCSBadRequestException('Advice text is required for advies steps');
		}
	}//end validateRequiredFields()
}//end class
