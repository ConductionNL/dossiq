<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\ParaafResumeListener;
use OCA\Dossiq\Service\Parafeer\ParaafFlowLinkage;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ParaafResumeListener.
 *
 * @covers \OCA\Dossiq\Listener\ParaafResumeListener
 */
class ParaafResumeListenerTest extends TestCase {

	/**
	 * Signals the fake runner delivered.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $signals = [];

	/**
	 * Objects the fake object service was asked to save.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Whether the assignee rule admits the acting user.
	 *
	 * @var boolean
	 */
	private bool $mayAnswer = true;

	/**
	 * A paraaf as the parafering surfaces create it.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed> The paraaf.
	 */
	private function paraaf(array $overrides=[]): array {
		return ($overrides + [
			'id' => 'paraaf-1',
			'proposal' => 'voorstel-1',
			'step' => 2,
			'actor' => 'behandelaar',
			'actorType' => 'user',
			'action' => 'parafered',
			'mandate' => 'MB-2024-07',
			'onBehalfOf' => 'wethouder',
		]);

	}//end paraaf()

	/**
	 * The creation event for an object.
	 *
	 * @param array<string, mixed> $object The created object.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private function event(array $object): ObjectCreatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($object);

		return new ObjectCreatedEvent($entity);

	}//end event()

	/**
	 * The listener, wired to doubles that record what it does.
	 *
	 * @param string      $flowRunId What the voorstel reports as its run.
	 * @param array       $slots     The run's per-node resume slots.
	 * @param string|null $uid       The acting user.
	 *
	 * @return ParaafResumeListener The listener.
	 */
	private function listener(string $flowRunId, array $slots, ?string $uid='behandelaar'): ParaafResumeListener {
		$saved = &$this->saved;

		$linkage = $this->createMock(ParaafFlowLinkage::class);
		$linkage->method('runDriving')->willReturn($flowRunId);
		$linkage
			->method('stamp')
			->willReturnCallback(
				static function (array $paraaf, string $runUuid, string $nodeId) use (&$saved): void {
					$saved[] = array_merge($paraaf, ['flowRun' => $runUuid, 'flowNode' => $nodeId]);
				}
			);

		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setContext([FlowResumeState::CONTEXT_KEY => $slots]);

		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('findByUuid')->willReturn($run);

		$runner = new class($this->signals) extends FlowRunService {

			/**
			 * @param array<int, array<string, mixed>> $sink Signals sink.
			 */
			public function __construct(private array &$sink) {
			}

			/**
			 * @param FlowRun              $run     The run.
			 * @param array<string, mixed> $payload The payload.
			 *
			 * @return FlowRun|null The run.
			 */
			public function signal(FlowRun $run, array $payload=[]): ?FlowRun {
				$this->sink[] = ['run' => $run->getUuid(), 'payload' => $payload];

				return $run;
			}

		};

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$assignees = new class($this->mayAnswer) extends FlowRunAssignee {

			/**
			 * @param boolean $answer Whether to admit.
			 */
			public function __construct(private bool &$answer) {
			}

			/**
			 * @param FlowRun     $run The run.
			 * @param string|null $uid The user.
			 *
			 * @return boolean Whether admitted.
			 */
			public function mayAnswer(FlowRun $run, ?string $uid): bool {
				return $this->answer;
			}

		};

		return new ParaafResumeListener(
			linkage: $linkage,
			runs: $runs,
			runner: $runner,
			userSession: $session,
			groups: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			assignees: $assignees
		);

	}//end listener()

	/**
	 * The awaiting slot a suspended paraaf step leaves behind.
	 *
	 * @return array<string, array<string, mixed>> The slots.
	 */
	private function awaitingSlots(): array {
		return [
			'step-1' => ['askedAt' => '2026-09-01T10:00:00+00:00', 'assignee' => 'behandelaar'],
		];

	}//end awaitingSlots()

	/**
	 * 🔴 A given paraaf resumes the run, carrying the approver's own decision.
	 *
	 * Not a bare "completed": the steps after this one branch on WHICH way it
	 * went, and a returned voorstel must not read as an approved one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAGivenParaafResumesTheRunWithItsDecision(): void {
		$this->listener('run-1', $this->awaitingSlots())->handle($this->event($this->paraaf()));

		$this->assertCount(1, $this->signals);
		$this->assertSame('run-1', $this->signals[0]['run']);
		$this->assertSame('parafered', $this->signals[0]['payload']['decision']);
		$this->assertSame('step-1', $this->signals[0]['payload']['node']);

	}//end testAGivenParaafResumesTheRunWithItsDecision()

	/**
	 * A returned paraaf resumes with 'returned', not with an approval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAReturnedParaafSaysSo(): void {
		$this->listener('run-1', $this->awaitingSlots())
			->handle($this->event($this->paraaf(['action' => 'returned'])));

		$this->assertSame('returned', $this->signals[0]['payload']['decision']);

	}//end testAReturnedParaafSaysSo()

	/**
	 * The administrative record travels with the signal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testTheMandateChainTravelsWithTheSignal(): void {
		$this->listener('run-1', $this->awaitingSlots())->handle($this->event($this->paraaf()));

		$payload = $this->signals[0]['payload'];
		$this->assertSame('MB-2024-07', $payload['mandate']);
		$this->assertSame('wethouder', $payload['onBehalfOf']);

	}//end testTheMandateChainTravelsWithTheSignal()

	/**
	 * 🔴 The paraaf is stamped with the run AND the node it answers.
	 *
	 * A run holds one awaiting slot per node and cannot say which of them a
	 * signal answers, so a paraaf naming only the run could not resume it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testTheParaafIsStampedWithTheRunAndTheNode(): void {
		$this->listener('run-1', $this->awaitingSlots())->handle($this->event($this->paraaf()));

		$this->assertCount(1, $this->saved);
		$this->assertSame('run-1', $this->saved[0]['flowRun']);
		$this->assertSame('step-1', $this->saved[0]['flowNode']);

	}//end testTheParaafIsStampedWithTheRunAndTheNode()

	/**
	 * 🔴 Somebody who is not the assignee cannot sign the step.
	 *
	 * FlowRunService::signal() is reachable without OpenRegister's HTTP resume
	 * endpoint, so its assignee guard is NOT inherited. A paraaf is a
	 * signature; without this check any user who can write a parafeeractie
	 * could sign off somebody else's step.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testANonAssigneeCannotResumeTheRun(): void {
		$this->mayAnswer = false;

		$this->listener('run-1', $this->awaitingSlots())->handle($this->event($this->paraaf()));

		$this->assertSame([], $this->signals, 'the run must not advance');
		// And nothing is stamped either: the paraaf stands, the linkage does not.
		$this->assertSame([], $this->saved);

	}//end testANonAssigneeCannotResumeTheRun()

	/**
	 * A voorstel driven by its route snapshot resumes nothing.
	 *
	 * This is every voorstel today, and the assertion that keeps the old path
	 * untouched by the new one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAVoorstelWithNoRunResumesNothing(): void {
		$this->listener('', $this->awaitingSlots())->handle($this->event($this->paraaf()));

		$this->assertSame([], $this->signals);
		$this->assertSame([], $this->saved);

	}//end testAVoorstelWithNoRunResumesNothing()

	/**
	 * A run with no awaiting slot is not waiting on a paraaf.
	 *
	 * Signalling it would either be lost or would resurrect a finished run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testARunWithNoAwaitingSlotResumesNothing(): void {
		$this->listener('run-1', ['step-1' => ['answeredAt' => 'yesterday']])
			->handle($this->event($this->paraaf()));

		$this->assertSame([], $this->signals);

	}//end testARunWithNoAwaitingSlotResumesNothing()

	/**
	 * An object that is not a paraaf resumes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testANonParaafObjectIsIgnored(): void {
		$this->listener('run-1', $this->awaitingSlots())
			->handle($this->event(['id' => 'zaak-1', 'title' => 'a case, not a paraaf']));

		$this->assertSame([], $this->signals);
		$this->assertSame([], $this->saved);

	}//end testANonParaafObjectIsIgnored()

}//end class
