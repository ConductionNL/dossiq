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
 *   objection.contestedDecision -> every person on that decision.
 *
 * The persons on a decision are its decidedBy, @self.owner, createdBy,
 * steller and signatory, and each entry of its signatories. A panel member
 * who matches any of them blocks the panel.
 *
 * A panel whose independence cannot be verified is refused: no objection
 * id, no store, a missing objection or decision schema, a store read that
 * throws, or a chain that ends without any person answers the same refusal
 * without a conflicting member, and is logged.
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
	 * The decision fields that each hold one person UID.
	 */
	private const PERSON_FIELDS = ['decidedBy', 'createdBy', 'steller', 'signatory'];

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
	 * Compares each panel member UID against every person on the contested
	 * primair besluit. An empty panel is clear; any other panel whose
	 * decision persons cannot be resolved is refused.
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
		if ($panel === []) {
			return $this->verdictFor(conflicting: null);
		}

		$lookup = $this->lookupFor(objectionId: $objectionId);
		if ($lookup === false) {
			return $this->verdictFor(conflicting: false);
		}

		return $this->verdictFor(
			conflicting: $this->conflictingMember(lookup: $lookup, panel: $panel)
		);
	}//end check()

	/**
	 * The verdict for the outcome of the check.
	 *
	 * @param string|false|null $conflicting The conflicting member UID, null when the panel is clear, false when
	 *                                       independence could not be verified.
	 *
	 * @return array{ok: bool, member: ?string, reason: ?string} The verdict.
	 *
	 * @spec openspec/changes/an-unverifiable-panel-does-not-start-deliberating/specs/bezwaar-advisory-committee/spec.md
	 */
	private function verdictFor(string|false|null $conflicting): array {
		if ($conflicting === false) {
			return [
				'ok' => false,
				'member' => null,
				'reason' => self::UNVERIFIABLE_REASON,
			];
		}

		if ($conflicting === null) {
			return [
				'ok' => true,
				'member' => null,
				'reason' => null,
			];
		}

		return [
			'ok' => false,
			'member' => $conflicting,
			'reason' => 'Lid was betrokken bij het bestreden '
						. 'besluit (Awb Art. 7:13 lid 3)',
		];
	}//end verdictFor()

	/**
	 * The object service and the configured register and schemas for one objection.
	 *
	 * @param string $objectionId The bezwaar (lifecycle) UUID.
	 *
	 * @return Lookup|false The lookup, or false when there is no objection id, no store or no configured schemas.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function lookupFor(string $objectionId): array|false {
		if ($objectionId === '') {
			$this->logger->warning(
				'Dossiq BAC: advice request names no objection; panel independence cannot be verified'
			);
			return false;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$this->logger->warning(
				'Dossiq BAC: no object store; panel independence cannot be verified'
			);
			return false;
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
	 * The first panel member who is a person on the contested decision, when there is one.
	 *
	 * @param Lookup        $lookup The store and schemas for the objection.
	 * @param array<string> $panel  Panel member UIDs.
	 *
	 * @return string|false|null The conflicting member UID, null when none conflicts, false when a store read
	 *                           threw or no person on the decision could be resolved.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function conflictingMember(array $lookup, array $panel): string|false|null {
		try {
			$persons = $this->personsOn(
				decision: $this->resolveContestedDecision(lookup: $lookup)
			);
			if ($persons === []) {
				$this->logger->warning(
					'Dossiq BAC: no person on the contested decision could be resolved; '
					. 'panel independence cannot be verified'
				);
				return false;
			}

			return $this->findConflictingPanelMember(
				panel: $panel,
				persons: $persons,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq BAC: independence check error: ' . $e->getMessage()
			);
			return false;
		}//end try
	}//end conflictingMember()

	/**
	 * Resolve the primair besluit contested by the objection filed on the
	 * bezwaar's underlying dossiq case.
	 *
	 * The lookup's `legacyObjSchema` (config key `bezwaar_schema`) may be ''.
	 *
	 * @param Lookup $lookup The store, the objection id, the register and the schemas.
	 *
	 * @return array<mixed> The decision, or [] when it cannot be resolved.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function resolveContestedDecision(array $lookup): array {
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
			return [];
		}

		$contestedId = (string)($objection['contestedDecision'] ?? '');
		if ($contestedId === '') {
			return [];
		}

		$decision = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $lookup['decisionSchema'],
			id: $contestedId
		);
		return ($decision ?? []);
	}//end resolveContestedDecision()

	/**
	 * Every person UID on a decision: its owner, its person fields and its signatories.
	 *
	 * @param array<mixed> $decision The decision.
	 *
	 * @return array<string> The distinct non-empty person UIDs.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function personsOn(array $decision): array {
		$candidates = [($decision['@self']['owner'] ?? null)];
		foreach (self::PERSON_FIELDS as $field) {
			$candidates[] = ($decision[$field] ?? null);
		}

		foreach ((array)($decision['signatories'] ?? []) as $signatory) {
			$candidates[] = $signatory;
		}

		$persons = array_map(fn (mixed $candidate): string => $this->personUid(value: $candidate), $candidates);

		return array_values(array_unique(array_diff($persons, [''])));
	}//end personsOn()

	/**
	 * The person UID one decision value names: a scalar, or an entry with a `uid` or `userId`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The UID, or '' when the value names no person.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function personUid(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['uid'] ?? ($value['userId'] ?? null));
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end personUid()

	/**
	 * Find the first panel member who is a person on the contested decision.
	 *
	 * @param array<string> $panel   Panel member UIDs.
	 * @param array<string> $persons The person UIDs on the contested decision.
	 *
	 * @return string|null The conflicting member UID, or null when the panel is independent.
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function findConflictingPanelMember(array $panel, array $persons): ?string {
		foreach ($panel as $memberUid) {
			if (in_array((string)$memberUid, $persons, true) === true) {
				return (string)$memberUid;
			}
		}

		return null;
	}//end findConflictingPanelMember()
}//end class
