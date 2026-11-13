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
 * The listener takes two sources. `procest` is what dossiq's own delegation
 * raises under. `decidiq-flow` is what Decidiq's `decidiq.request-decision`
 * flow step raises under, which replaced dossiq's own decision step (change
 * flow-nodes-to-their-owners); such a decision is projected only when its
 * SUBJECT is a dossiq case, because that step raises decisions for any app's
 * objects. For a flow decision Decidiq wakes the waiting run itself, so this
 * listener does not. Events raised by any other app are ignored. Its own derivation failures are
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
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\AdvisoryCommitteeServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseOutcomeProductIssuerInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\BesluitMaterialisationService;
use OCA\Dossiq\Service\DecisionDocument\ConcludedDecisionRecord;
use OCA\Dossiq\Service\Flow\WaitingRunResumer;
use OCA\Dossiq\Service\Support\FlowDecisionSubject;
use OCA\OpenRegister\Db\FlowRunMapper;
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
 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
 */
class DecisionConcludedListener implements IEventListener {

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
	 * Resolves the record and case of the concluded decision, and records an advice deviation.
	 *
	 * @var ConcludedDecisionRecord
	 */
	private readonly ConcludedDecisionRecord $records;

	/**
	 * Wakes the flow run that waits on the concluded decision.
	 *
	 * @var WaitingRunResumer
	 */
	private readonly WaitingRunResumer $resumer;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Schema/register/ObjectService bridge.
	 * @param BesluitMaterialisationService $decisionMaterialiser ZGW Besluit projection from the outcome.
	 * @param AdvisoryCommitteeServiceInterface $bacService BAC advice-request audit writer (Awb art. 7:13 lid 7).
	 * @param LoggerInterface $logger Logger.
	 * @param FlowRunMapper|null $runs Finds the run suspended on this decision. Nullable
	 *                                 so adding it breaks no existing construction site;
	 *                                 absent, no run is resumed, which leaves a flow
	 *                                 waiting rather than advancing it wrongly.
	 * @param FlowRunService|null $runner Delivers the resume signal. Nullable for the
	 *                                    same reason and with the same safe direction.
	 * @param FlowDecisionSubject|null $flowDecisions Recognises a flow decision about a dossiq case;
	 *                                        subject. Nullable so no construction site breaks;
	 *                                        absent, no flow decision is projected.
	 * @param CaseOutcomeProductIssuerInterface|null $products Issues the product a case type declares for this outcome
	 *                                                (decision 172); absent, no product is written.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly BesluitMaterialisationService $decisionMaterialiser,
		AdvisoryCommitteeServiceInterface $bacService,
		private readonly LoggerInterface $logger,
		?FlowRunMapper $runs = null,
		?FlowRunService $runner = null,
		private readonly ?FlowDecisionSubject $flowDecisions = null,
		private readonly ?CaseOutcomeProductIssuerInterface $products = null,
	) {
		$this->records = new ConcludedDecisionRecord(bacService: $bacService, logger: $logger);
		$this->resumer = new WaitingRunResumer(logger: $logger, runs: $runs, runner: $runner);
	}//end __construct()

	/**
	 * Handle a decidesk `DecisionConcludedEvent`.
	 *
	 * @param Event $event The dispatched event (decidesk DecisionConcludedEvent).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	public function handle(Event $event): void {
		// Defensive duck-typing: the event class is decidesk's and is optional
		// at runtime, so guard against any non-conforming dispatch.
		if (method_exists($event, 'getSourceApp') === false) {
			return;
		}

		try {
			$this->projectConcluded(event: $event);
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
	 * Project a terminal decision onto its case, then record what follows from it.
	 *
	 * @param Event $event The decidesk DecisionConcludedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	private function projectConcluded(Event $event): void {
		$run = $this->terminalRun(event: $event);
		if ($run === null) {
			return;
		}

		$this->resolveCase(run: $run);
		if ($run->caseId === '') {
			$this->logger->warning(
				'Dossiq DecisionConcludedListener: could not resolve a case for the concluded decision',
				['decisionId' => $run->decisionId, 'externalReference' => $run->externalRef]
			);
			return;
		}

		$this->materialise(run: $run);
		$this->recordConsequences(run: $run);
	}//end projectConcluded()

	/**
	 * The run for a terminal decision this app projects, or null when it projects none.
	 *
	 * @param Event $event The decidesk DecisionConcludedEvent.
	 *
	 * @return DecisionConcludedRun|null
	 *
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	private function terminalRun(Event $event): ?DecisionConcludedRun {
		// REQ-PDCD-003: only project events this app raised, or that
		// Decidiq's flow step raised about one of this app's cases.
		$flowCase = ($this->flowDecisions?->caseIdOf(event: $event) ?? '');
		if ($flowCase === '' && $this->readString(event: $event, getter: 'getSourceApp') !== self::SOURCE_APP) {
			return null;
		}

		$status = strtolower($this->readString(event: $event, getter: 'getStatus'));
		if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
			// Non-terminal (e.g. pending): nothing to materialise yet.
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$run = new DecisionConcludedRun();
		$run->event = $event;
		$run->flowCase = $flowCase;
		$run->status = $status;
		$run->objectService = $objectService;
		return $run;
	}//end terminalRun()

	/**
	 * Resolve the case and existing besluit of the run's decision.
	 *
	 * Locate the dossiq domain record carrying this decisionRef so we can
	 * resolve the owning case and any existing besluitRef. Fall back to the
	 * externalReference / subjectId as the case identifier.
	 *
	 * @param DecisionConcludedRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	private function resolveCase(DecisionConcludedRun $run): void {
		$run->decisionId = $this->readString(event: $run->event, getter: 'getDecisionId');
		$run->subjectId = $this->readString(event: $run->event, getter: 'getSubjectId');
		$run->externalRef = $this->referenceOf(event: $run->event, flowCase: $run->flowCase);

		[$caseId, $besluitId, $decisionRecord] = $this->records->resolveCaseAndDecision(
			objectService: $run->objectService,
			register: $this->readString(event: $run->event, getter: 'getSubjectRegister'),
			schema: $this->readString(event: $run->event, getter: 'getSubjectSchema'),
			decisionId: $run->decisionId,
			subjectId: $run->subjectId,
			externalRef: $run->externalRef
		);
		$run->caseId = $caseId;
		$run->besluitId = $besluitId;
		$run->decisionRecord = $decisionRecord;
	}//end resolveCase()

	/**
	 * Materialise the ZGW Besluit on the run's case from the decided outcome.
	 *
	 * @param DecisionConcludedRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	private function materialise(DecisionConcludedRun $run): void {
		$this->decisionMaterialiser->materialiseFromConcludedEvent(
			caseId: $run->caseId,
			decisionId: $run->besluitId,
			event: $this->projectOutcome(event: $run->event)
		);

		$this->logger->info(
			'Dossiq DecisionConcludedListener: materialised ZGW Besluit from decidesk outcome',
			['decisionId' => $run->decisionId, 'caseId' => $run->caseId, 'status' => $run->status]
		);
	}//end materialise()

	/**
	 * Record what the materialised besluit sets off: the council deviation, the product, the waiting run.
	 *
	 * @param DecisionConcludedRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-09-dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	private function recordConsequences(DecisionConcludedRun $run): void {
		// Awb art. 7:13 lid 7: a besluit op bezwaar that departs from the
		// BAC advice must carry a documented motivation. The besluit is
		// FINAL at this point, so this is where the deviation becomes a
		// fact worth recording on the advice request's OpenRegister audit
		// trail.
		$this->records->recordCouncilDeviation(
			decision: $run->decisionRecord,
			decisionId: $run->besluitId,
			subjectId: $run->subjectId
		);

		// Decision 172: the product the case type declares; never throws.
		$this->products?->onConcludedDecision(
			caseId: $run->caseId,
			decisionId: $run->decisionId,
			status: $run->status,
			decidedAt: $this->readString(event: $run->event, getter: 'getDecidedAt')
		);

		// A case flow that ASKED for this decision is suspended on it.
		// Deliberately last: the besluit is materialised before the run is
		// woken, so the steps after the decision see a case that already
		// carries its outcome rather than racing the projection.
		// A flow decision's run is woken by Decidiq, which raised it.
		if ($run->flowCase === '') {
			$this->resumer->resumeWaitingRun(caseId: $run->caseId, decisionRef: $run->decisionId, status: $run->status);
		}
	}//end recordConsequences()

	/**
	 * The reference the case is looked up by.
	 *
	 * A flow decision's external reference is `flow-run:<run>:<node>`, which
	 * names the run, not the case: its subject is the case.
	 *
	 * @param Event  $event    The event.
	 * @param string $flowCase The case a flow decision is about, or ''.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 */
	private function referenceOf(Event $event, string $flowCase): string {
		if ($flowCase !== '') {
			return $flowCase;
		}

		return $this->readString(event: $event, getter: 'getExternalReference');
	}//end referenceOf()

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
		if (is_scalar($value) === false) {
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
		$signers = $this->signersOf(event: $event);

		return [
			'status' => $this->readString(event: $event, getter: 'getStatus'),
			'outcome' => $this->readString(event: $event, getter: 'getOutcome'),
			'decidedAt' => $this->readString(event: $event, getter: 'getDecidedAt'),
			'signer' => $this->firstSigner(signers: $signers),
			'method' => $this->methodOf(event: $event),
			'signers' => $signers,
			'signingReference' => $this->readString(event: $event, getter: 'getSigningReference'),
		];
	}//end projectOutcome()

	/**
	 * The event's signers, or [] when it carries none.
	 *
	 * @param Event $event The decidesk DecisionConcludedEvent.
	 *
	 * @return array<int|string, mixed>
	 */
	private function signersOf(Event $event): array {
		if (method_exists($event, 'getSigners') === false) {
			return [];
		}

		$rawSigners = $event->getSigners();
		if (is_array($rawSigners) === false) {
			return [];
		}

		return $rawSigners;
	}//end signersOf()

	/**
	 * The first signer (if any), recorded as the mandaathouder on the Besluit.
	 *
	 * @param array<int|string, mixed> $signers The event's signers.
	 *
	 * @return string The signer's id or name, or ''.
	 */
	private function firstSigner(array $signers): string {
		if ($signers === []) {
			return '';
		}

		// A signer entry is either a record or a bare user id; normalise
		// the bare form to the record shape so one read covers both.
		$first = reset($signers);
		if (is_array($first) === false) {
			$first = ['id' => (string)$first];
		}

		return (string)($first['id'] ?? $first['name'] ?? '');
	}//end firstSigner()

	/**
	 * The decision method: "signature" when the outcome was signed, otherwise
	 * the decisionType, which carries the method provenance.
	 *
	 * @param Event $event The decidesk DecisionConcludedEvent.
	 *
	 * @return string
	 */
	private function methodOf(Event $event): string {
		if (method_exists($event, 'isSigned') === true && $event->isSigned() === true) {
			return 'signature';
		}

		return $this->readString(event: $event, getter: 'getDecisionType');
	}//end methodOf()
}//end class
