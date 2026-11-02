<?php

/**
 * Dossiq Bezwaar Panel Independence Checker.
 *
 * The Awb Art. 7:13 lid 3 independence check for a bezwaaradviescommissie
 * panel. Split out of AdvisoryCommitteeService so that service keeps only
 * the advice-request lifecycle: the entire question "was any member of
 * this panel involved in the besluit now under bezwaar?" — including the
 * four-hop resolution chain that answers it — lives here and nowhere
 * else.
 *
 * Resolution chain:
 *   bacAdviceRequest.bezwaar -> bezwaar (lifecycle record) -> bezwaar.case
 *   (dossiq case) -> objection (filed on that case) ->
 *   objection.contestedDecision -> decision owner / createdBy / steller.
 *
 * A panel whose independence cannot be verified is refused: a missing
 * objection or decision schema, or a store read that throws, answers the
 * same refusal without a conflicting member, and is logged.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verifies that no BAC panel member authored the contested primair besluit.
 *
 * @phpstan-type Lookup array{
 *     objectService: object,
 *     objectionId: string,
 *     register: string,
 *     legacyObjSchema: string,
 *     objectionSchema: string,
 *     decisionSchema: string
 * }
 * @psalm-type Lookup = array{
 *     objectService: object,
 *     objectionId: string,
 *     register: string,
 *     legacyObjSchema: string,
 *     objectionSchema: string,
 *     decisionSchema: string
 * }
 *
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 */
class PanelIndependenceChecker {

	use SearchesObjects;

	/**
	 * The refusal reason when the check could not run.
	 */
	private const UNVERIFIABLE_REASON = 'Onafhankelijkheid van het panel kon niet '
		. 'worden vastgesteld (Awb Art. 7:13 lid 3)';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Member-independence check per Awb Art. 7:13(3).
	 *
	 * Compares each panel member UID against the `createdBy` (steller) of
	 * the contested primair besluit.
	 *
	 * @param string $objectionId The bezwaar (lifecycle) UUID.
	 * @param array<string> $panel Panel member UIDs.
	 *
	 * @return array{ok: bool, member: ?string, reason: ?string} The verdict; a refusal
	 *                                                          without a member means
	 *                                                          independence could not
	 *                                                          be verified.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	public function check(string $objectionId, array $panel): array {
		$clear = [
			'ok' => true,
			'member' => null,
			'reason' => null,
		];

		if ($objectionId === '' || $panel === []) {
			return $clear;
		}

		$unverifiable = [
			'ok' => false,
			'member' => null,
			'reason' => self::UNVERIFIABLE_REASON,
		];

		$lookup = $this->lookupFor(objectionId: $objectionId);
		if ($lookup === false) {
			return $unverifiable;
		}

		if ($lookup === null) {
			return $clear;
		}

		$conflicting = $this->conflictingMember(lookup: $lookup, panel: $panel);
		if ($conflicting === false) {
			return $unverifiable;
		}

		if ($conflicting === null) {
			return $clear;
		}

		return [
			'ok' => false,
			'member' => $conflicting,
			'reason' => 'Lid was betrokken bij het bestreden '
						. 'besluit (Awb Art. 7:13 lid 3)',
		];
	}//end check()

	/**
	 * The object service and the configured register and schemas for one objection.
	 *
	 * @param string $objectionId The bezwaar (lifecycle) UUID.
	 *
	 * @return Lookup|false|null The lookup, null when there is no store, false when the schemas are not configured.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function lookupFor(string $objectionId): array|false|null {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$legacyObjSchema = $this->settingsService->getConfigValue(key: 'bezwaar_schema');
		$objectionSchema = $this->settingsService->getConfigValue(key: 'objection_schema');
		$decisionSchema = $this->settingsService->getConfigValue(key: 'decision_schema');

		if (in_array('', [$objectionSchema, $decisionSchema], true) === true) {
			$this->logger->warning(
				'Dossiq BAC: objection/decision schemas not configured; '
				. 'panel independence cannot be verified'
			);
			return false;
		}

		return [
			'objectService' => $objectService,
			'objectionId' => $objectionId,
			'register' => $register,
			'legacyObjSchema' => $legacyObjSchema,
			'objectionSchema' => $objectionSchema,
			'decisionSchema' => $decisionSchema,
		];
	}//end lookupFor()

	/**
	 * The panel member who authored the contested decision, when there is one.
	 *
	 * @param Lookup        $lookup The store and schemas for the objection.
	 * @param array<string> $panel  Panel member UIDs.
	 *
	 * @return string|false|null The conflicting member UID, null when none conflicts, false when a store read threw.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function conflictingMember(array $lookup, array $panel): string|false|null {
		try {
			$author = $this->resolveContestedDecisionAuthor(lookup: $lookup);
			if ($author === '') {
				return null;
			}

			return $this->findConflictingPanelMember(
				panel: $panel,
				author: $author,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq BAC: independence check error: ' . $e->getMessage()
			);
			return false;
		}//end try
	}//end conflictingMember()

	/**
	 * Resolve the steller (author) of the primair besluit contested by the
	 * objection filed on the bezwaar's underlying dossiq case.
	 *
	 * The lookup's `legacyObjSchema` (config key `bezwaar_schema`) may be ''.
	 *
	 * @param Lookup $lookup The store, the objection id, the register and the schemas.
	 *
	 * @return string The steller UID, or '' when it cannot be resolved.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function resolveContestedDecisionAuthor(array $lookup): string {
		$objectService = $lookup['objectService'];
		$objectionId = $lookup['objectionId'];
		$register = $lookup['register'];
		$legacyObjSchema = $lookup['legacyObjSchema'];
		// Resolve the underlying dossiq case via the bezwaar entity
		// when the bezwaar_schema is registered. When unavailable
		// (e.g. legacy callers passing a case UUID directly), fall back
		// to treating the input as the case id.
		$caseId = $objectionId;
		if ($legacyObjSchema !== '') {
			$bezwaar = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $legacyObjSchema,
				id: $objectionId
			);
			if ($bezwaar !== null) {
				$caseId = (string)($bezwaar['case'] ?? $objectionId);
			}
		}

		$objections = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $lookup['objectionSchema'],
			filters: ['case' => $caseId]
		);
		$objection = null;
		if ($objections !== []) {
			$objection = $objections[0];
		}

		if (is_array($objection) === false) {
			return '';
		}

		$contestedId = (string)($objection['contestedDecision'] ?? '');
		if ($contestedId === '') {
			return '';
		}

		$decision = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $lookup['decisionSchema'],
			id: $contestedId
		);
		if ($decision === null) {
			return '';
		}

		return (string)(
			$decision['@self']['owner'] ?? ($decision['createdBy'] ?? ($decision['steller'] ?? ''))
		);
	}//end resolveContestedDecisionAuthor()

	/**
	 * Find the first panel member that is not independent from the steller.
	 *
	 * @param array<string> $panel Panel member UIDs.
	 * @param string $author UID of the contested decision's author.
	 *
	 * @return string|null The conflicting member UID, or null when the panel is independent.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function findConflictingPanelMember(array $panel, string $author): ?string {
		foreach ($panel as $memberUid) {
			if ((string)$memberUid === $author) {
				return (string)$memberUid;
			}
		}

		return null;
	}//end findConflictingPanelMember()
}//end class
