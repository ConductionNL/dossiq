<?php

/**
 * Retiring a case type, and bringing it back.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use OCA\Dossiq\Service\CaseType\CaseTypeLifecycleState;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Takes a case type out of use without deleting it, and puts it back.
 *
 * 🔑 RETIRED IS A STATE, NOT A DELETE, AND THAT IS THE WHOLE POINT. A regeling
 * that ended still has running cases, and those cases still have to be readable
 * and finishable years later. `case-delete-guard` already refuses to delete a
 * case type that has cases; retirement is the act that guard leaves missing, so
 * an administrator whose only option was Delete now has the one they wanted.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
class CaseTypeRetirementService {

	/**
	 * The app config key naming the case type schema.
	 */
	public const CASE_TYPES = 'case_type_schema';

	/**
	 * Constructor.
	 *
	 * @param StarterStore           $store   The OpenRegister seam.
	 * @param CaseTypeLifecycleState $state   The derived state.
	 * @param IUserSession           $session Who is acting.
	 * @param LoggerInterface        $logger  Logger.
	 */
	public function __construct(
		private readonly StarterStore $store,
		private readonly CaseTypeLifecycleState $state,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stop a case type taking new cases, from today.
	 *
	 * @param string $caseTypeId The case type's id.
	 *
	 * @return array{ok: bool, reason: string, state: string} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function retire(string $caseTypeId): array {
		$caseType = $this->store->row(configKey: self::CASE_TYPES, id: $caseTypeId);
		if ($caseType === null) {
			return ['ok' => false, 'reason' => 'not_found', 'state' => ''];
		}

		$current = $this->state->stateOf(caseType: $caseType);
		if ($current === CaseTypeLifecycleState::RETIRED) {
			return ['ok' => false, 'reason' => 'already_retired', 'state' => $current];
		}

		if ($current === CaseTypeLifecycleState::DRAFT) {
			// A draft takes no cases already. Retiring it would write an end
			// date that means nothing and read as an act that was performed.
			return ['ok' => false, 'reason' => 'is_draft', 'state' => $current];
		}

		// Yesterday, not today. `validUntil` is inclusive, so a case type
		// retired with today's date still accepts cases for the rest of the
		// day, which is not what the administrator pressed the button for.
		$caseType['validUntil'] = gmdate('Y-m-d', strtotime('yesterday'));

		return $this->record(
			caseType: $caseType,
			caseTypeId: $caseTypeId,
			act: 'retired',
		);
	}//end retire()

	/**
	 * Offer a retired case type again.
	 *
	 * @param string $caseTypeId The case type's id.
	 *
	 * @return array{ok: bool, reason: string, state: string} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function restore(string $caseTypeId): array {
		$caseType = $this->store->row(configKey: self::CASE_TYPES, id: $caseTypeId);
		if ($caseType === null) {
			return ['ok' => false, 'reason' => 'not_found', 'state' => ''];
		}

		if ($this->state->stateOf(caseType: $caseType) !== CaseTypeLifecycleState::RETIRED) {
			return ['ok' => false, 'reason' => 'not_retired', 'state' => $this->state->stateOf(caseType: $caseType)];
		}

		$caseType['validUntil'] = null;

		// A start date in the future also reads as retired, and clearing only
		// the end date would leave the type exactly as it was. Clearing both is
		// what "offer it again" means.
		$from = (string)($caseType['validFrom'] ?? '');
		if ($from !== '' && $from > gmdate('Y-m-d')) {
			$caseType['validFrom'] = gmdate('Y-m-d');
		}

		return $this->record(
			caseType: $caseType,
			caseTypeId: $caseTypeId,
			act: 'restored',
		);
	}//end restore()

	/**
	 * Write the case type and note who changed its state.
	 *
	 * The note lives on the case type itself because dossiq has no general
	 * administrative act record yet: `statusRecord` is status-transition shaped
	 * and carries no actor at all, and `lifecycle-acts-on-the-case` is a change
	 * with none of its tasks done. Two fields on the row are readable today and
	 * move into that record when it lands, rather than waiting for it.
	 *
	 * @param array<string, mixed> $caseType   The case type, already amended.
	 * @param string               $caseTypeId The case type's id.
	 * @param string               $act        Either retired or restored.
	 *
	 * @return array{ok: bool, reason: string, state: string} What happened.
	 */
	private function record(array $caseType, string $caseTypeId, string $act): array {
		$user = $this->session->getUser();
		$actor = '';
		if ($user !== null) {
			$actor = $user->getUID();
		}

		$caseType['lifecycleAct'] = $act;
		$caseType['lifecycleActBy'] = $actor;
		$caseType['lifecycleActAt'] = gmdate('c');

		$saved = $this->store->save(
			configKey: self::CASE_TYPES,
			payload: $caseType,
			id: $caseTypeId,
		);

		if ($saved === null) {
			return ['ok' => false, 'reason' => 'write_failed', 'state' => ''];
		}

		$this->logger->info(
			'Dossiq starter: a case type changed state',
			['caseType' => $caseTypeId, 'act' => $act, 'by' => $caseType['lifecycleActBy']]
		);

		return ['ok' => true, 'reason' => '', 'state' => $this->state->stateOf(caseType: $saved)];
	}//end record()
}//end class
