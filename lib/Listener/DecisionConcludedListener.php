<?php

/**
 * Dossiq Decision Concluded Listener.
 *
 * Consumes decidesk's terminal `DecisionConcludedEvent` and materialises the
 * ZGW `Besluit` on the matching dossiq case from the decided outcome. decidesk
 * owns the *making* of the decision; this listener records the ZGW `Besluit` for
 * the zaak dossier (Besluiten API) as a PROJECTION of the decidesk outcome —
 * dossiq never authors the besluit locally.
 *
 * This REPLACES the former poll-and-consume outcome path that was removed from
 * the delegation service: the full outcome now arrives synchronously on the
 * event, so there is no decidesk poll.
 *
 * The listener filters strictly to `getSourceApp() === 'procest'`; events raised
 * by any other consuming app are ignored. Its own derivation failures are
 * swallowed + logged so a defective lookup never blocks event delivery — but it
 * NEVER materialises a besluit on an absent/non-terminal outcome.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\BesluitMaterialisationService;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Materialises the ZGW Besluit from decidesk's `DecisionConcludedEvent`.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
 */
class DecisionConcludedListener implements IEventListener {
	use SearchesObjects;

	/**
	 * This app's source-app marker on the decidesk event.
	 *
	 * FROZEN at `procest`: this is this app's id AS OTHER APPS KNOW IT, not our
	 * own app id. decidesk still ships `<id>decidesk</id>` and echoes this value
	 * back verbatim, and events already in flight or persisted there carry
	 * `sourceApp: procest`. A mismatch is a silently ignored event, not an error.
	 * It moves only in a coordinated pass that moves emitter and receiver together.
	 */
	private const SOURCE_APP = 'procest';

	/**
	 * Terminal decidesk statuses that materialise a Besluit. `pending` is
	 * non-terminal and is ignored (no besluit yet).
	 */
	private const TERMINAL_STATUSES = ['approved', 'rejected', 'withdrawn'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register/ObjectService bridge.
	 * @param BesluitMaterialisationService $decisionMaterialiser ZGW Besluit projection from the outcome.
	 * @param AdvisoryCommitteeService $bacService BAC advice-request audit writer (Awb art. 7:13 lid 7).
	 * @param LoggerInterface $logger Logger.
	 * @param FlowRunMapper|null $runs Finds the run suspended on this decision. Nullable
	 *                                 so adding it breaks no existing construction site;
	 *                                 absent, no run is resumed, which leaves a flow
	 *                                 waiting rather than advancing it wrongly.
	 * @param FlowRunService|null $runner Delivers the resume signal. Nullable for the
	 *                                    same reason and with the same safe direction.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly BesluitMaterialisationService $decisionMaterialiser,
		private readonly AdvisoryCommitteeService $bacService,
		private readonly LoggerInterface $logger,
		private readonly ?FlowRunMapper $runs = null,
		private readonly ?FlowRunService $runner = null,
	) {
	}//end __construct()

	/**
	 * Handle a decidesk `DecisionConcludedEvent`.
	 *
	 * @param Event $event The dispatched event (decidesk DecisionConcludedEvent).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	public function handle(Event $event): void {
		// Defensive duck-typing: the event class is decidesk's and is optional
		// at runtime, so guard against any non-conforming dispatch.
		if (method_exists($event, 'getSourceApp') === false) {
			return;
		}

		try {
			// REQ-PDCD-003: only project events this app raised.
			if ((string)$event->getSourceApp() !== self::SOURCE_APP) {
				return;
			}

			$status = strtolower($this->readString(event: $event, getter: 'getStatus'));
			if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
				// Non-terminal (e.g. pending): nothing to materialise yet.
				return;
			}

			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return;
			}

			$decisionId = $this->readString(event: $event, getter: 'getDecisionId');
			$register = $this->readString(event: $event, getter: 'getSubjectRegister');
			$schema = $this->readString(event: $event, getter: 'getSubjectSchema');
			$subjectId = $this->readString(event: $event, getter: 'getSubjectId');
			$externalRef = $this->readString(event: $event, getter: 'getExternalReference');

			// Locate the dossiq domain record carrying this decisionRef so we
			// can resolve the owning case and any existing besluitRef. Fall back
			// to the externalReference / subjectId as the case identifier.
			[$caseId, $besluitId, $decisionRecord] = $this->resolveCaseAndDecision(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				decisionId: $decisionId,
				subjectId: $subjectId,
				externalRef: $externalRef
			);

			if ($caseId === '') {
				$this->logger->warning(
					'Dossiq DecisionConcludedListener: could not resolve a case for the concluded decision',
					['decisionId' => $decisionId, 'externalReference' => $externalRef]
				);
				return;
			}

			$this->decisionMaterialiser->materialiseFromConcludedEvent(
				caseId: $caseId,
				decisionId: $besluitId,
				event: $this->projectOutcome(event: $event)
			);

			$this->logger->info(
				'Dossiq DecisionConcludedListener: materialised ZGW Besluit from decidesk outcome',
				['decisionId' => $decisionId, 'caseId' => $caseId, 'status' => $status]
			);

			// Awb art. 7:13 lid 7: a besluit op bezwaar that departs from the
			// BAC advice must carry a documented motivation. The besluit is
			// FINAL at this point, so this is where the deviation becomes a
			// fact worth mirroring onto the advice request's own append-only
			// audit trail.
			$this->recordCouncilDeviation(
				decision: $decisionRecord,
				decisionId: $besluitId,
				subjectId: $subjectId
			);

			// A case flow that ASKED for this decision is suspended on it.
			// Deliberately last: the besluit is materialised before the run is
			// woken, so the steps after the decision see a case that already
			// carries its outcome rather than racing the projection.
			$this->resumeWaitingRun(caseId: $caseId, decisionRef: $decisionId, status: $status);
		} catch (Throwable $e) {
			// Never block event delivery on our own derivation failure; never
			// author a besluit on a failed outcome.
			$this->logger->warning(
				'Dossiq DecisionConcludedListener: could not materialise Besluit from decidesk outcome: '
				. $e->getMessage()
			);
		}//end try
	}//end handle()

	/**
	 * Wake the case flow that was waiting on this decision, if one was.
	 *
	 * 🔑 MATCHED ON THE decisionRef, NOT ON THE CASE. A case has several
	 * decisions in its life, and a run suspended on the SECOND one must not be
	 * advanced by the first concluding. Matching on the case would do exactly
	 * that — and would look correct, because the run does belong to that case.
	 *
	 * So the run is only signalled when one of its awaiting node slots records
	 * this very ref. A run waiting on a different decision is left suspended,
	 * which is the spec'd behaviour and not an omission.
	 *
	 * Never throws into the caller: a decision concludes whether or not a flow
	 * was listening, and a failure to resume must not undo the besluit that was
	 * already materialised above.
	 *
	 * @param string $caseId      The case the decision belongs to.
	 * @param string $decisionRef The concluded decision's id.
	 * @param string $status      The terminal status decidiq reported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function resumeWaitingRun(string $caseId, string $decisionRef, string $status): void {
		if ($this->runs === null || $this->runner === null || trim($decisionRef) === '') {
			return;
		}

		try {
			$suspended = $this->runs->findSuspendedBySubject(subjectUuid: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not look up runs suspended on case ' . $caseId . ': ' . $e->getMessage()
			);
			return;
		}

		foreach ($suspended as $run) {
			if ($this->awaitsDecision(run: $run, decisionRef: $decisionRef) === false) {
				continue;
			}

			try {
				$this->runner->signal(
					run: $run,
					payload: [
						// Without a `decision` key the resume is a nudge and the
						// node suspends again — so the outcome is what makes
						// this an answer, and it is decidiq's word, not ours.
						'decision' => $status,
						'decisionRef' => $decisionRef,
						'caseId' => $caseId,
					]
				);

				$this->logger->info(
					'Dossiq: resumed flow run ' . (string)$run->getUuid() . ' on decision ' . $decisionRef
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: could not resume flow run on a concluded decision: ' . $e->getMessage(),
					['run' => $run->getUuid(), 'decisionRef' => $decisionRef]
				);
			}//end try

			return;
		}//end foreach
	}//end resumeWaitingRun()

	/**
	 * Whether a suspended run is waiting on this particular decision.
	 *
	 * Reads the per-node resume slots the requesting node wrote. A run holds one
	 * slot per node across its life, so the question is whether ANY slot names
	 * this ref — not whether the run belongs to the right case, which the
	 * caller has already established and which is not sufficient.
	 *
	 * @param object $run         The suspended run.
	 * @param string $decisionRef The concluded decision's id.
	 *
	 * @return boolean True when a slot records this ref.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function awaitsDecision(object $run, string $decisionRef): bool {
		$context = ($run->getContext() ?? []);
		if (is_array($context) === false) {
			return false;
		}

		$slots = ($context[FlowResumeState::CONTEXT_KEY] ?? []);
		if (is_array($slots) === false) {
			return false;
		}

		foreach ($slots as $slot) {
			if (is_array($slot) === false) {
				continue;
			}

			if (trim((string)($slot['decisionRef'] ?? '')) === trim($decisionRef)) {
				return true;
			}
		}

		return false;
	}//end awaitsDecision()

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
	 */
	private function resolveCaseAndDecision(
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
	}//end resolveCaseAndBesluit()

	/**
	 * Mirror an Awb art. 7:13 lid 7 council deviation onto the linked BAC
	 * advice request.
	 *
	 * `DecisionValidator::assertPublishable()` already REFUSES to raise a
	 * bezwaarDecision that sets `advisoryOpinion`, clears `followsAdvice`
	 * and leaves `deviationRationale` empty, so by the time decidesk
	 * concludes, a deviating decision is guaranteed to carry its motivation.
	 * This method only records that fact on the advice request's own
	 * append-only audit trail so the beroep dossier export can demonstrate
	 * compliance from either side of the referral.
	 *
	 * A decision that follows the advice, or that was never referred to a
	 * committee, is a no-op. The write itself is swallow-and-log inside
	 * {@see AdvisoryCommitteeService::recordCouncilDeviation()}, so a
	 * failure here never blocks besluit materialisation.
	 *
	 * @param array<string,mixed>|null $decision The bezwaarDecision record, when one matched.
	 * @param string $decisionId The materialised ZGW Besluit ref, when known.
	 * @param string $subjectId The bezwaarDecision UUID from the event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
	 */
	private function recordCouncilDeviation(
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

		$this->bacService->recordCouncilDeviation(
			requestId: $requestId,
			decisionId: $reference,
			rationaleRef: $rationale
		);
	}//end recordCouncilDeviation()

	/**
	 * Read a duck-typed getter off the decidesk event as a string.
	 *
	 * The event is typed as the base Event class because the concrete
	 * OCA\Decidesk\Event\DecisionConcludedEvent is an optional runtime
	 * dependency that is absent from this app's autoload graph. Every read goes
	 * through this helper so a non-conforming dispatch degrades to an empty
	 * string instead of raising an Error.
	 *
	 * @param Event $event The decidesk DecisionConcludedEvent.
	 * @param string $getter The zero-argument getter to invoke.
	 *
	 * @return string The stringified getter result, or '' when absent/null.
	 */
	private function readString(Event $event, string $getter): string {
		if (method_exists($event, $getter) === false) {
			return '';
		}

		$value = $event->$getter();
		if ($value === null || is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end readString()

	/**
	 * Project the decidesk event getters into the materialiser's outcome shape.
	 *
	 * The $event parameter is typed as the base Event class because the concrete
	 * OCA\Decidesk\Event\DecisionConcludedEvent is an optional runtime dependency,
	 * so every getter is read through the duck-typed {@see readString()} helper.
	 *
	 * @param Event $event The decidesk DecisionConcludedEvent.
	 *
	 * @return array<string,mixed> Normalised projection: status, outcome, decidedAt, signer, method, signers, signingReference.
	 */
	private function projectOutcome(Event $event): array {
		$signers = [];
		if (method_exists($event, 'getSigners') === true) {
			$rawSigners = $event->getSigners();
			if (is_array($rawSigners) === true) {
				$signers = $rawSigners;
			}
		}

		// First signer (if any) is recorded as the mandaathouder on the Besluit.
		$signer = '';
		if ($signers !== []) {
			// A signer entry is either a record or a bare user id; normalise
			// the bare form to the record shape so one read covers both.
			$first = reset($signers);
			if (is_array($first) === false) {
				$first = ['id' => (string)$first];
			}

			$signer = (string)($first['id'] ?? $first['name'] ?? '');
		}

		// The decision method is recorded as "signature" when the outcome was
		// signed, otherwise the decisionType carries the method provenance.
		$method = $this->readString(event: $event, getter: 'getDecisionType');
		if (method_exists($event, 'isSigned') === true && $event->isSigned() === true) {
			$method = 'signature';
		}

		return [
			'status' => $this->readString(event: $event, getter: 'getStatus'),
			'outcome' => $this->readString(event: $event, getter: 'getOutcome'),
			'decidedAt' => $this->readString(event: $event, getter: 'getDecidedAt'),
			'signer' => $signer,
			'method' => $method,
			'signers' => $signers,
			'signingReference' => $this->readString(event: $event, getter: 'getSigningReference'),
		];
	}//end projectOutcome()
}//end class
