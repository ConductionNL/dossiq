<?php

/**
 * DecisionConcludedListener Unit Tests
 *
 * Verifies that dossiq materialises the ZGW Besluit from decidesk's
 * DecisionConcludedEvent: events for this source app with a terminal status are
 * projected onto the matching case via BesluitMaterialisationService; events
 * from another source app, or with a non-terminal status, are ignored
 * (REQ-PDCD-003).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

// The CURRENT namespace. The decision app renamed OCA\Decidesk -> OCA\Decidiq
// with no alias, and the production resolver now prefers the current spelling —
// so a test importing the OLD class asserts against an object the code no longer
// builds. CrossAppEventNamesTest guards the ordering these follow.
use OCA\Decidiq\Event\DecisionConcludedEvent;
use OCA\Dossiq\Listener\DecisionConcludedListener;
use OCA\Dossiq\Service\BesluitMaterialisationService;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\Permit\PermitIssuer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\CaseObjectReference;
use OCA\Dossiq\Service\Support\FlowDecisionSubject;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Object-service stub exposing the OpenRegister search/find surface the
 * SearchesObjects trait calls.
 */
interface ConcludedObjectServiceStub {
	/**
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string,mixed> $filters Filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters): array;
}//end interface

/**
 * Unit tests for DecisionConcludedListener.
 *
 * @covers \OCA\Dossiq\Listener\DecisionConcludedListener
 * @uses \OCA\Dossiq\Service\Support\CaseObjectReference
 * @uses \OCA\Dossiq\Service\Support\FlowDecisionSubject
 * @uses \OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 */
class DecisionConcludedListenerTest extends TestCase {
	use MakesBezwaarAuditTrail;

	/**
	 * A terminal decidesk outcome for this app materialises the ZGW Besluit.
	 *
	 * @return void
	 */
	public function testMaterialisesBesluitForDossiqSourceApp(): void {
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')
			->willReturn([['decisionRef' => 'dec-1', 'case' => 'case-9', 'besluitRef' => 'bes-2']]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->once())
			->method('materialiseFromConcludedEvent')
			->willReturnCallback(
				function (string $caseId, string $decisionId, array $event): array {
					$this->assertSame('case-9', $caseId);
					$this->assertSame('bes-2', $decisionId);
					$this->assertSame('approved', $event['status']);
					return ['ok' => true];
				}
			);

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testMaterialisesBesluitForDossiqSourceApp()

	/**
	 * Events from another source app are ignored.
	 *
	 * @return void
	 */
	public function testIgnoresOtherSourceApp(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->never())->method('materialiseFromConcludedEvent');

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event(sourceApp: 'docudesk', status: 'approved'));
	}//end testIgnoresOtherSourceApp()

	/**
	 * A non-terminal (pending) status does not materialise a Besluit.
	 *
	 * @return void
	 */
	public function testIgnoresNonTerminalStatus(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->never())->method('materialiseFromConcludedEvent');

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'pending'));
	}//end testIgnoresNonTerminalStatus()

	/**
	 * A concluded besluit that departs from the BAC advice mirrors the
	 * deviation onto the advice request's audit trail (Awb art. 7:13 lid 7).
	 *
	 * @return void
	 */
	public function testRecordsCouncilDeviationWhenDecisionDepartsFromAdvice(): void {
		$bac = $this->createMock(AdvisoryCommitteeService::class);

		$listener = $this->listenerForDecision(
			record: [
				'decisionRef' => 'dec-1',
				'case' => 'case-9',
				'besluitRef' => 'bes-2',
				'advisoryOpinion' => 'bac-req-7',
				'followsAdvice' => false,
				'deviationRationale' => 'Commissie miste de nieuwe feiten',
			],
			bac: $bac
		);

		$bac->expects($this->once())
			->method('recordCouncilDeviation')
			->with('bac-req-7', 'bes-2', 'Commissie miste de nieuwe feiten');

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testRecordsCouncilDeviationWhenDecisionDepartsFromAdvice()

	/**
	 * A besluit that follows the committee advice records no deviation.
	 *
	 * @return void
	 */
	public function testRecordsNoDeviationWhenDecisionFollowsAdvice(): void {
		$bac = $this->createMock(AdvisoryCommitteeService::class);

		$listener = $this->listenerForDecision(
			record: [
				'decisionRef' => 'dec-1',
				'case' => 'case-9',
				'besluitRef' => 'bes-2',
				'advisoryOpinion' => 'bac-req-7',
				'followsAdvice' => true,
			],
			bac: $bac
		);

		$bac->expects($this->never())->method('recordCouncilDeviation');

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testRecordsNoDeviationWhenDecisionFollowsAdvice()

	/**
	 * A besluit that was never referred to a committee records no deviation.
	 *
	 * @return void
	 */
	public function testRecordsNoDeviationWhenNoCommitteeWasInvolved(): void {
		$bac = $this->createMock(AdvisoryCommitteeService::class);

		$listener = $this->listenerForDecision(
			record: [
				'decisionRef' => 'dec-1',
				'case' => 'case-9',
				'besluitRef' => 'bes-2',
			],
			bac: $bac
		);

		$bac->expects($this->never())->method('recordCouncilDeviation');

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));
	}//end testRecordsNoDeviationWhenNoCommitteeWasInvolved()

	/**
	 * A concluded decision resumes the run whose slot names its ref.
	 *
	 * The signal must carry decidiq's verdict as the `decision` (a payload
	 * without one is a nudge, and the awaiting node suspends again), plus the
	 * ref, so the requesting node can tell this answer from any other.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function testAConcludedDecisionResumesTheRunThatAskedForIt(): void {
		$signals = [];

		$listener = $this->listenerForRuns(
			runs: [$this->suspendedRun(uuid: 'run-7', decisionRef: 'dec-1')],
			signals: $signals
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$this->assertCount(1, $signals);
		$this->assertSame('run-7', $signals[0]['run']);
		$this->assertSame('approved', $signals[0]['payload']['decision']);
		$this->assertSame('dec-1', $signals[0]['payload']['decisionRef']);
	}//end testAConcludedDecisionResumesTheRunThatAskedForIt()

	/**
	 * A run waiting on a DIFFERENT decision is left suspended.
	 *
	 * The match is on the decisionRef, not on the case: this run belongs to the
	 * right case, so matching on the case would wrongly advance it. Leaving it
	 * suspended is the spec'd behaviour, not an omission.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function testAnUnrelatedDecisionLeavesTheRunSuspended(): void {
		$signals = [];

		$listener = $this->listenerForRuns(
			runs: [$this->suspendedRun(uuid: 'run-7', decisionRef: 'dec-OTHER')],
			signals: $signals
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$this->assertSame([], $signals);
	}//end testAnUnrelatedDecisionLeavesTheRunSuspended()

	/**
	 * Of several suspended runs, only the one naming the ref is signalled.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function testOnlyTheRunNamingTheRefIsResumed(): void {
		$signals = [];

		$listener = $this->listenerForRuns(
			runs: [
				$this->suspendedRun(uuid: 'run-a', decisionRef: 'dec-OTHER'),
				$this->suspendedRun(uuid: 'run-b', decisionRef: 'dec-1'),
			],
			signals: $signals
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$this->assertCount(1, $signals);
		$this->assertSame('run-b', $signals[0]['run']);
	}//end testOnlyTheRunNamingTheRefIsResumed()

	/**
	 * Without the flow collaborators the listener still materialises quietly.
	 *
	 * The nullable mapper/runner exist so older construction sites keep
	 * working; absent, no run is resumed, and nothing raises.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function testWithoutFlowCollaboratorsNothingIsResumedAndNothingRaises(): void {
		$listener = $this->listenerForDecision(
			record: ['decisionRef' => 'dec-1', 'case' => 'case-9', 'besluitRef' => 'bes-2'],
			bac: $this->createMock(AdvisoryCommitteeService::class)
		);

		$listener->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$this->addToAssertionCount(1);
	}//end testWithoutFlowCollaboratorsNothingIsResumedAndNothingRaises()

	/**
	 * A suspended run whose resume slot records the given decisionRef.
	 *
	 * @param string $uuid        The run uuid.
	 * @param string $decisionRef The ref the run's requesting node stored.
	 *
	 * @return FlowRun
	 */
	private function suspendedRun(string $uuid, string $decisionRef): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setContext(
			[
				'resumeState' => [
					'decide-commissie' => ['decisionRef' => $decisionRef],
				],
			]
		);

		return $run;
	}//end suspendedRun()

	/**
	 * Build a listener whose case lookup resolves and whose flow collaborators
	 * see the given suspended runs, recording every signal into $signals.
	 *
	 * @param FlowRun[] $runs The suspended runs the mapper reports for the case.
	 * @param array $signals Sink for delivered signals (by reference).
	 *
	 * @return DecisionConcludedListener
	 */
	private function listenerForRuns(array $runs, array &$signals): DecisionConcludedListener {
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')
			->willReturn([['decisionRef' => 'dec-1', 'case' => 'case-9', 'besluitRef' => 'bes-2']]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('findSuspendedBySubject')->willReturn($runs);

		$runner = new class($signals) extends FlowRunService {
			/**
			 * @param array $sink Where delivered signals land.
			 */
			public function __construct(private array &$sink) {
			}

			/**
			 * Record the signal instead of delivering it.
			 *
			 * @param FlowRun $run The run being signalled.
			 * @param array $payload The signal payload.
			 *
			 * @return FlowRun|null
			 */
			public function signal(FlowRun $run, array $payload = []): ?FlowRun {
				$this->sink[] = ['run' => $run->getUuid(), 'payload' => $payload];

				return $run;
			}
		};

		return new DecisionConcludedListener(
			$settings,
			$this->createMock(BesluitMaterialisationService::class),
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class),
			$mapper,
			$runner
		);
	}//end listenerForRuns()

	/**
	 * Build a listener whose decisionRef lookup resolves to $record.
	 *
	 * @param array<string,mixed> $record The bezwaarDecision record the search returns.
	 * @param AdvisoryCommitteeService $bac The BAC service mock the listener writes through.
	 *
	 * @return DecisionConcludedListener
	 */
	private function listenerForDecision(array $record, AdvisoryCommitteeService $bac): DecisionConcludedListener {
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([$record]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new DecisionConcludedListener(
			$settings,
			$this->createMock(BesluitMaterialisationService::class),
			$bac,
			$this->createMock(LoggerInterface::class)
		);
	}//end listenerForDecision()

	/**
	 * Decision 172: an approved outcome asks the permit issuer about the
	 * resolved case; a rejected one does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-permits-as-held-products/tasks.md#2.1
	 */
	public function testAnApprovedOutcomeIssuesThePermitAndARejectedOneDoesNot(): void {
		foreach (['approved' => 1, 'rejected' => 0] as $status => $times) {
			$objectService = $this->createMock(ConcludedObjectServiceStub::class);
			$objectService->method('searchObjectsBySlug')
				->willReturn([['decisionRef' => 'dec-1', 'case' => 'case-9', 'besluitRef' => 'bes-2']]);
			$settings = $this->createMock(SettingsService::class);
			$settings->method('getObjectService')->willReturn($objectService);
			$materialiser = $this->createMock(BesluitMaterialisationService::class);
			$materialiser->method('materialiseFromConcludedEvent')->willReturn(['ok' => true]);

			$permits = $this->createMock(PermitIssuer::class);
			$permits->expects($this->exactly($times))
				->method('onApprovedDecision')
				->with('case-9', 'dec-1', '2026-06-15T10:00:00+00:00')
				->willReturn(null);

			$listener = new DecisionConcludedListener(
				settingsService: $settings,
				decisionMaterialiser: $materialiser,
				bacService: $this->createMock(AdvisoryCommitteeService::class),
				logger: $this->createMock(LoggerInterface::class),
				permits: $permits
			);
			$listener->handle($this->event(sourceApp: 'procest', status: $status));
		}
	}//end testAnApprovedOutcomeIssuesThePermitAndARejectedOneDoesNot()


	/**
	 * Build a DecisionConcludedEvent fixture.
	 *
	 * @param string $sourceApp The source app id.
	 * @param string $status The terminal/non-terminal status.
	 *
	 * @return DecisionConcludedEvent
	 */
	private function event(string $sourceApp, string $status): DecisionConcludedEvent {
		return new DecisionConcludedEvent(
			'dec-1',
			'contract-renewal',
			$status,
			'granted',
			false,
			null,
			[],
			'2026-06-15T10:00:00+00:00',
			$sourceApp,
			'register-slug',
			'supplierContract',
			'sub-1',
			'case-9',
			'corr-1'
		);
	}//end event()
	/**
	 * The case recogniser over dossiq's configured register (12) and case schema (34).
	 *
	 * @return CaseObjectReference The recogniser.
	 */
	private function caseReference(): CaseObjectReference {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => '12', 'case_schema' => '34'][$key] ?? ''
		);

		return new CaseObjectReference($settings);
	}//end caseReference()

	/**
	 * A decision raised by Decidiq's flow step.
	 *
	 * @param string $register The subject register.
	 * @param string $schema   The subject schema.
	 *
	 * @return DecisionConcludedEvent The event.
	 */
	private function flowEvent(string $register, string $schema): DecisionConcludedEvent {
		return new DecisionConcludedEvent(
			'dec-7',
			'advice',
			'approved',
			'granted',
			false,
			null,
			[],
			'2026-09-28T10:00:00+00:00',
			'decidiq-flow',
			$register,
			$schema,
			'case-5',
			'flow-run:run-1:decide-commissie',
			'corr-7'
		);
	}//end flowEvent()

	/**
	 * A flow decision about a dossiq case becomes its besluit, on the subject, and wakes no run.
	 *
	 * Decidiq's own listener wakes the run that asked; waking it here as well
	 * would signal it twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function testAFlowDecisionOnADossiqCaseIsMaterialisedOnItsSubject(): void {
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->once())
			->method('materialiseFromConcludedEvent')
			->with('case-5', '', $this->anything())
			->willReturn(['ok' => true]);

		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->expects($this->never())->method('findSuspendedBySubject');

		$listener = new DecisionConcludedListener(
			$settings,
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class),
			$mapper,
			null,
			new FlowDecisionSubject($this->caseReference())
		);

		$listener->handle($this->flowEvent(register: '12', schema: '34'));
	}//end testAFlowDecisionOnADossiqCaseIsMaterialisedOnItsSubject()

	/**
	 * A flow decision about another app's object is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function testAFlowDecisionOnAnotherAppsObjectIsIgnored(): void {
		$materialiser = $this->createMock(BesluitMaterialisationService::class);
		$materialiser->expects($this->never())->method('materialiseFromConcludedEvent');

		$listener = new DecisionConcludedListener(
			$this->createMock(SettingsService::class),
			$materialiser,
			$this->createMock(AdvisoryCommitteeService::class),
			$this->createMock(LoggerInterface::class),
			null,
			null,
			new FlowDecisionSubject($this->caseReference())
		);

		$listener->handle($this->flowEvent(register: '12', schema: '99'));
		$listener->handle($this->flowEvent(register: 'pipelinq', schema: 'case'));
	}//end testAFlowDecisionOnAnotherAppsObjectIsIgnored()

	/**
	 * A deviating decision writes an Awb art. 7:13 row on the advice request (REQ-BAT-001).
	 *
	 * Through the listener, on the real AdvisoryCommitteeService and BezwaarAuditTrail.
	 *
	 * @return void
	 */
	public function testADeviatingDecisionWritesAnAwb713RowOnTheAdviceRequest(): void {
		$this->startBezwaarStore();
		$this->store->seed('bacAdviceRequest', 'req-7', ['bezwaar' => 'bezwaar-1', 'status' => 'advice-issued']);

		$this->listenerOverRealBac(logger: $this->createMock(LoggerInterface::class))
			->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$context = $this->rowContext(uuid: 'req-7', action: 'dossiq.bezwaar.council-deviation-recorded');
		$this->assertSame('awb-art-7:13', $context['tag']);
		$this->assertSame(['decision' => 'bes-2', 'motivatie' => 'Commissie miste de nieuwe feiten'], $context['payload']);
		$this->assertArrayNotHasKey('auditTrail', $this->store->row('bacAdviceRequest', 'req-7'));
	}//end testADeviatingDecisionWritesAnAwb713RowOnTheAdviceRequest()

	/**
	 * A deviation entry that cannot be written is logged by the listener with the entry (REQ-BAT-003).
	 *
	 * @return void
	 */
	public function testAFailedDeviationEntryIsLoggedWithTheEntry(): void {
		$this->startBezwaarStore();
		$this->store->seed('bacAdviceRequest', 'req-7', ['bezwaar' => 'bezwaar-1', 'status' => 'advice-issued']);
		$this->trail->failsAll = true;

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string|\Stringable $message, array $context = []) use (&$logged): void {
				$logged[] = [(string) $message, $context];
			}
		);

		$this->listenerOverRealBac(logger: $logger)->handle($this->event(sourceApp: 'procest', status: 'approved'));

		$entries = array_values(array_filter($logged, static fn (array $line): bool => ($line[1]['action'] ?? '') === 'dossiq.bezwaar.council-deviation-recorded'));
		$this->assertCount(1, $entries, 'the listener must log the unwritten deviation entry once, at error level');
		$this->assertSame('req-7', $entries[0][1]['object']);
		$this->assertSame('awb-art-7:13', $entries[0][1]['entry']['tag']);
		$this->assertSame('Commissie miste de nieuwe feiten', $entries[0][1]['entry']['payload']['motivatie']);
	}//end testAFailedDeviationEntryIsLoggedWithTheEntry()

	/**
	 * A listener over the real BAC service, whose decision lookup finds a deviating besluit.
	 *
	 * @param LoggerInterface $logger The listener's logger.
	 *
	 * @return DecisionConcludedListener The listener.
	 */
	private function listenerOverRealBac(LoggerInterface $logger): DecisionConcludedListener {
		$record = [
			'decisionRef' => 'dec-1',
			'case' => 'case-9',
			'besluitRef' => 'bes-2',
			'advisoryOpinion' => 'req-7',
			'followsAdvice' => false,
			'deviationRationale' => 'Commissie miste de nieuwe feiten',
		];
		$objectService = $this->createMock(ConcludedObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([$record]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new DecisionConcludedListener(
			$settings,
			$this->createMock(BesluitMaterialisationService::class),
			$this->realAdvisoryService(trail: $this->bezwaarAuditTrail(uid: null)),
			$logger
		);
	}//end listenerOverRealBac()
}//end class
