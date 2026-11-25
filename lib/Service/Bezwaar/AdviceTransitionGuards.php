<?php

/**
 * Dossiq AdviceTransitionGuards
 *
 * The checks an advice request passes before it moves: a panel of independent
 * members before deliberation starts, and the structured advice, raised as a
 * decision, before advice is issued.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The guards of an advice request's moves (REQ-BAC-2, REQ-BAC-4).
 *
 * Reads no store: AdvisoryCommitteeService hands in the stored request with the
 * store it lives in, and writes the move once these guards have passed.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class AdviceTransitionGuards {

	use ReadsPanelMembers;

	/**
	 * Trail event and message of an independence refusal: 0 names a
	 * conflicting member, 1 could not verify independence.
	 */
	private const INDEPENDENCE_REFUSALS = [
		['independence-check-failed', 'Panel member conflict (Awb Art. 7:13 lid 3): '],
		['independence-check-unverifiable', 'Panel independence could not be verified (Awb Art. 7:13 lid 3): '],
	];

	/**
	 * Required structured-advice fields when transitioning to advice-issued.
	 */
	private const REQUIRED_ADVICE_FIELDS = [
		'conclusion',
		'recommendation',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface         $logger           Logger
	 * @param AdviceDelegationService $adviceDelegation Advice delegation to decidesk (ADR-019)
	 * @param BezwaarAuditTrail       $auditTrail       Writes a refusal onto OpenRegister's audit trail
	 * @param PanelIndependenceChecker $independence    Awb Art. 7:13 lid 3 panel check
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly AdviceDelegationService $adviceDelegation,
		private readonly BezwaarAuditTrail $auditTrail,
		private readonly PanelIndependenceChecker $independence,
	) {
	}//end __construct()

	/**
	 * Run the guard the move needs, and answer the advice decision it raised.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 * @param array<string, mixed> $move The caller's patch with its `from` and target `status`.
	 *
	 * @return string The decidesk Decision reference, '' when the move raises none.
	 *
	 * @throws RuntimeException|GuardFailedException When the guard refuses.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-004-the-awb-and-idor-domain-rules-stay-in-dossiq
	 */
	public function guardMove(array $request, array $move): string {
		// Guard: assigned → in-deliberation requires panel and
		// independence (REQ-BAC-2).
		if ($move['from'] === 'assigned' && $move['status'] === 'in-deliberation') {
			$this->guardPanel(request: $request);
		}

		// Guard: in-deliberation → advice-issued requires the structured
		// advice content (REQ-BAC-4).
		if ($move['from'] === 'in-deliberation' && $move['status'] === 'advice-issued') {
			return $this->issueAdviceDecision(request: $request, payload: $move);
		}

		return '';
	}//end guardMove()

	/**
	 * Guard a move into in-deliberation (REQ-BAC-2): a panel must be set and
	 * every member must be independent. An independence failure is recorded on
	 * the request's trail before the guard raises.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no panel has been set, it holds only blank members, or a member is not a user id
	 * @throws GuardFailedException When a panel member is not independent, or independence cannot be verified
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 * @spec openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
	 */
	public function guardPanel(array $request): void {
		$panel = $this->requirePanelMembers(panel: (array)($request['current']['panel'] ?? []));

		$independence = $this->independence->check(
			objectionId: $this->objectionIdOf(request: $request['current']),
			panel: $panel,
		);

		if ($independence['ok'] !== false) {
			return;
		}

		// A refusal without a member means independence could not be verified.
		[$event, $message] = self::INDEPENDENCE_REFUSALS[(int)($independence['member'] === null)];

		// Recorded before raising, and refused whether or not the entry is
		// written; an unwritten entry goes to the error log in full.
		$this->auditTrail->recordRefusal(
			register: $request['register'],
			schema: $request['schema'],
			objectUuid: $request['id'],
			event: $event,
			payload: [
				'conflictingMember' => $independence['member'],
				'reason' => $independence['reason'],
			],
		);

		throw new GuardFailedException(
			failedGuards: [],
			message: $message . $independence['reason']
		);
	}//end guardPanel()

	/**
	 * Validate the structured advice content (REQ-BAC-4) and raise the
	 * decidesk `advice` Decision (REQ-PDRD-001 / REQ-PDRD-002).
	 *
	 * The BAC advice is *made* in decidesk; this fails CLOSED and never
	 * authors the advice outcome locally as a fallback.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string, current: array<string, mixed>} $request The stored request.
	 * @param array<string, mixed> $payload Caller-supplied patch
	 *
	 * @return string The decidesk Decision reference
	 *
	 * @throws RuntimeException When a required advice field is missing or the
	 *                          decision service is unavailable
	 *
	 * @spec exclude one guard of guardMove(), which carries the requirement
	 */
	private function issueAdviceDecision(array $request, array $payload): string {
		$merged = array_merge($request['current'], $payload);
		$this->requireAdviceFields(advice: $merged);

		try {
			return $this->adviceDelegation->raiseAdviceDecision(
				subjectSchema: 'bacAdviceRequest',
				subjectId: $request['id'],
				payload: [
					'subjectRegister' => $request['register'],
					'externalReference' => $this->externalReferenceFor(request: $request['current'], requestId: $request['id']),
					'subjectLabel' => (string)($merged['conclusion'] ?? 'BAC-advies'),
					'adviceType' => (string)($merged['recommendation'] ?? ''),
					'question' => (string)($merged['conclusion'] ?? ''),
				],
			);
		} catch (RuntimeException $e) {
			$this->logger->error(
				'Dossiq BAC: decidesk advice Decision raise failed — failing closed: '
				. $e->getMessage()
			);
			throw new RuntimeException('Decision service unavailable: ' . $e->getMessage(), 0, $e);
		}//end try
	}//end issueAdviceDecision()

	/**
	 * The structured advice must carry every required field.
	 *
	 * @param array<string, mixed> $advice The stored request with the caller's patch over it.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a required field is missing or empty.
	 *
	 * @spec exclude one guard of issueAdviceDecision(), which carries the requirement
	 */
	private function requireAdviceFields(array $advice): void {
		$missing = array_values(
			array_filter(
				self::REQUIRED_ADVICE_FIELDS,
				static fn (string $field): bool => in_array(($advice[$field] ?? null), [null, '', []], true)
			)
		);
		if ($missing !== []) {
			throw new RuntimeException(
				'Advice cannot be issued: missing required field '
				. $missing[0]
			);
		}
	}//end requireAdviceFields()

	/**
	 * The objection this advice request belongs to.
	 *
	 * `bezwaar` is the schema-declared property; `objectionProceeding` is the
	 * schema it $refs, and was written into the object by mistake for as long as
	 * this service has existed. Rows created before the fix carry only the wrong
	 * key, so the legacy branch stays until `BackfillAdviceRequestObjection` has
	 * run everywhere.
	 *
	 * The legacy branch LOGS. A silent fallback would make the repair step look
	 * unnecessary — the reads would keep working, the manifest filters would keep
	 * showing nothing, and the only symptom would stay invisible.
	 *
	 * @param array<string, mixed> $request The stored advice request.
	 *
	 * @return string The objection id, or '' when neither key is present.
	 *
	 * @spec exclude one read shared by the two guards in this class
	 */
	private function objectionIdOf(array $request): string {
		$objectionId = (string)($request['bezwaar'] ?? '');
		if ($objectionId !== '') {
			return $objectionId;
		}

		$legacy = (string)($request['objectionProceeding'] ?? '');
		if ($legacy !== '') {
			$this->logger->warning(
				'Dossiq BAC: advice request still carries the legacy objection key; run the backfill repair step',
				['app' => AppIdentity::APP_ID, 'requestId' => ($request['id'] ?? null)]
			);
		}

		return $legacy;
	}//end objectionIdOf()

	/**
	 * The external reference a raised advice decision carries.
	 *
	 * Prefers the objection, falling back to the advice request's own id so the
	 * decision is never raised with an empty reference.
	 *
	 * @param array<string, mixed> $request The stored advice request.
	 * @param string $requestId The advice request's own id.
	 *
	 * @return string The reference.
	 *
	 * @spec exclude one step of issueAdviceDecision(), which carries the requirement
	 */
	private function externalReferenceFor(array $request, string $requestId): string {
		$objectionId = $this->objectionIdOf(request: $request);

		return [$objectionId, $requestId][(int)($objectionId === '')];
	}//end externalReferenceFor()
}//end class
