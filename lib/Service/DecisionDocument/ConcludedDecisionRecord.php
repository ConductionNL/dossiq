<?php

/**
 * Dossiq concluded decision record.
 *
 * The domain record a concluded decision belongs to: which case owns it,
 * which decision reference it already carries, and, when the decision
 * departs from an advice it was referred for, the deviation mirrored onto
 * that advice request's audit trail.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionDocument
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
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the record and case of a concluded decision, and records an advice deviation.
 *
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */
class ConcludedDecisionRecord {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param AdvisoryCommitteeService $bacService Advice-request audit writer (Awb art. 7:13 lid 7).
	 * @param LoggerInterface          $logger     Logger.
	 */
	public function __construct(
		private readonly AdvisoryCommitteeService $bacService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the owning case UUID and any existing besluitRef for a decision.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The subject register (numeric ID or slug, may be empty).
	 * @param string $schema The subject schema (numeric ID or slug, may be empty).
	 * @param string $decisionId The decidesk decisionId stored on the record as `decisionRef`.
	 * @param string $subjectId The subject id from the event.
	 * @param string $externalRef The external reference (often the case/subject UUID).
	 *
	 * @return array{0:string,1:string,2:array<string,mixed>|null} [caseId, besluitId, decisionRecord].
	 *
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	public function resolveCaseAndDecision(
		object $objectService,
		string $register,
		string $schema,
		string $decisionId,
		string $subjectId,
		string $externalRef,
	): array {
		$record = null;
		if ($register !== '' && $schema !== '' && $decisionId !== '') {
			try {
				$matches = $this->searchObjectsAsArrays(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					filters: ['decisionRef' => $decisionId]
				);
				$record = ($matches[0] ?? null);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq DecisionConcludedListener: decisionRef lookup failed: ' . $e->getMessage()
				);
			}
		}

		if (is_array($record) === true) {
			$caseId = (string)($record['case'] ?? $record['caseRef'] ?? $externalRef);
			$besluitId = (string)($record['besluitRef'] ?? '');
			return [$caseId, $besluitId, $record];
		}

		// No record matched: use the external reference (then subjectId) as the
		// case identifier; no existing besluit is known.
		$caseId = $subjectId;
		if ($externalRef !== '') {
			$caseId = $externalRef;
		}

		return [$caseId, '', null];
	}//end resolveCaseAndDecision()

	/**
	 * Mirror an Awb art. 7:13 lid 7 council deviation onto the linked BAC
	 * advice request.
	 *
	 * `DecisionValidator::assertPublishable()` already REFUSES to raise a
	 * bezwaarDecision that sets `advisoryOpinion`, clears `followsAdvice`
	 * and leaves `deviationRationale` empty, so by the time decidesk
	 * concludes, a deviating decision is guaranteed to carry its motivation.
	 * This method only records that fact on the advice request's own
	 * OpenRegister audit trail so the beroep dossier export can demonstrate
	 * compliance from either side of the referral.
	 *
	 * A decision that follows the advice, or that was never referred to a
	 * committee, is a no-op. An entry that cannot be written is logged here at
	 * error level with the full entry, so a failure never blocks besluit
	 * materialisation and is never swallowed silently.
	 *
	 * @param array<string,mixed>|null $decision The bezwaarDecision record, when one matched.
	 * @param string $decisionId The materialised ZGW Besluit ref, when known.
	 * @param string $subjectId The bezwaarDecision UUID from the event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	public function recordCouncilDeviation(
		?array $decision,
		string $decisionId,
		string $subjectId,
	): void {
		if ($decision === null) {
			return;
		}

		$requestId = (string)($decision['advisoryOpinion'] ?? '');
		if ($requestId === '') {
			// Never referred to a committee — there is no advice to deviate from.
			return;
		}

		if ((bool)($decision['followsAdvice'] ?? true) === true) {
			return;
		}

		$rationale = (string)($decision['deviationRationale'] ?? '');

		// Prefer the materialised ZGW Besluit; fall back to the bezwaarDecision
		// itself, which IS the besluit op bezwaar when no ZGW ref is known yet.
		$reference = $decisionId;
		if ($reference === '') {
			$reference = (string)($decision['@self']['id'] ?? ($decision['id'] ?? $subjectId));
		}

		try {
			$this->bacService->recordCouncilDeviation(
				requestId: $requestId,
				decisionId: $reference,
				rationaleRef: $rationale
			);
		} catch (BezwaarEntryNotWrittenException $notWritten) {
			// The besluit stands; the Awb art. 7:13 record of the deviation did
			// not land. Log the whole entry so it is not lost silently.
			$this->logger->error(
				'Dossiq DecisionConcludedListener: council deviation entry not written',
				$notWritten->logContext()
			);
		}
	}//end recordCouncilDeviation()
}//end class
