<?php

/**
 * Characterisation tests for the order in which the status-transition engine checks, writes and answers.
 *
 * Written before the engine is split over two facades (method-decomposition slice 8h), green on the
 * single class. They pin what the five other engine tests leave open: the order of the refusals, the
 * conflict check, the one save of a move, the timer after the save, the record that cannot be written
 * back, the free-form refusals and the shape of the available transitions.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\ProcessOwnedStatusRule;
use OCA\Dossiq\Service\Status\StatusDeclarations;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\OfferedTransitions;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\Dossiq\Service\Transitions\StatusChecklist;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCA\Dossiq\Service\Transitions\TransitionDeclarations;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The order of the engine's checks and writes.
 *
 * @covers \OCA\Dossiq\Service\StatusTransitionService
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Transitions\GuardFailedException
 * @uses \OCA\Dossiq\Service\Transitions\TransitionSpecReader
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class StatusTransitionServiceOrderTest extends TestCase {
	private const TRANSITION = ['id' => 't1', 'label' => 'Besluiten', 'fromStatus' => 'st-open', 'toStatus' => 'st-closed'];

	/**
	 * What the collaborators answer. A test changes an entry before it builds the engine.
	 *
	 * @var array<string, mixed>
	 */
	private array $world = [];

	/**
	 * Every write and side effect, in the order it happened.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * The errors the engine logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * A world in which the move t1 goes through: an open case at version 3, nothing withheld.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$case        = ['id' => 'case-1', 'caseType' => 'ct-1', 'status' => 'st-open', '@self' => ['version' => 3]];
		$this->world = [
			'cases'          => [$case, $case],
			'transition'     => self::TRANSITION,
			'template'       => ['transitions' => [self::TRANSITION]],
			'withheld'       => [],
			'fourEyes'       => null,
			'groupOk'        => true,
			'admin'          => true,
			'guards'         => [['type' => 'role', 'passed' => true]],
			'final'          => false,
			'saveThrows'     => null,
			'updateThrows'   => null,
			'handSetRefused' => null,
			'foreignStatus'  => null,
		];
	}//end setUp()

	/**
	 * Build the engine over the world, every collaborator a double that records its writes.
	 *
	 * The one place the constructor is spelled out, so the split touches this helper and no test.
	 *
	 * @return StatusTransitionService
	 */
	private function engine(): StatusTransitionService {
		$world = &$this->world;

		$loader = $this->createMock(WorkflowTemplateLoader::class);
		$loader->method('getTransitionForCase')->willReturnCallback(static fn (): ?array => $world['transition']);
		$loader->method('getTemplateForCase')->willReturnCallback(static fn (): ?array => $world['template']);

		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->willReturnCallback(
			static function () use (&$world): ?array {
				return array_shift($world['cases']);
			}
		);
		$store->method('lookupStatusName')->willReturnCallback(static fn (string $statusTypeId): string => 'name of '.$statusTypeId);
		$store->method('lookupStatusColour')->willReturn('#123456');
		$store->method('lookupStatusDescription')->willReturn('What it means.');
		$store->method('saveCase')->willReturnCallback(
			function (array $case) use (&$world): array {
				if ($world['saveThrows'] !== null) {
					throw $world['saveThrows'];
				}

				$this->calls[] = ['saveCase', $case];
				$case['@self']['version'] = (($case['@self']['version'] ?? 0) + 1);

				return $case;
			}
		);
		$store->method('writeStatusRecord')->willReturnCallback(
			function (...$arguments): array {
				$names         = ['caseId', 'toStatus', 'fromStatus', 'label', 'comment', 'evaluatedGuards', 'noWorkflowTemplate', 'actor'];
				$this->calls[] = ['writeStatusRecord', array_combine($names, $arguments)];
				return ['id' => 'rec-1'];
			}
		);
		$store->method('updateStatusRecord')->willReturnCallback(
			function (array $record) use (&$world): array {
				if ($world['updateThrows'] !== null) {
					throw $world['updateThrows'];
				}

				$this->calls[] = ['updateStatusRecord', $record];

				return ($record + ['updated' => true]);
			}
		);
		$store->method('assertStatusBelongsToCaseType')->willReturnCallback(
			static function () use (&$world): void {
				if ($world['foreignStatus'] !== null) {
					throw $world['foreignStatus'];
				}
			}
		);

		$statuses = $this->createMock(StatusDeclarations::class);
		$statuses->method('panelFor')->willReturn(['waitingOn' => 'us', 'dwell' => ['days' => 2], 'derivation' => null]);
		$statuses->method('applyStatusChange')->willReturnCallback(
			static fn (array $case, string $toStatus): array => ($case + ['statusEnteredAt' => 'stamped for '.$toStatus])
		);
		$statuses->method('retime')->willReturnCallback(
			function (string $caseId, string $toStatus): ?string {
				$this->calls[] = ['retime', $caseId, $toStatus];
				return null;
			}
		);

		$moves = $this->createMock(TransitionDeclarations::class);
		$moves->method('withheldReasons')->willReturnCallback(static fn (): array => $world['withheld']);
		$moves->method('fourEyesRefusal')->willReturnCallback(static fn (): ?array => $world['fourEyes']);
		$moves->method('refusalSentence')->willReturn('Somebody else has to take this move.');

		$offered = $this->createMock(OfferedTransitions::class);
		$offered->method('from')->willReturn(['transitions' => [['id' => 't1']], 'withheld' => [['id' => 't2', 'reasons' => ['advice']]]]);

		$authorizer = $this->createMock(TransitionAuthorizer::class);
		$authorizer->method('isTransitionGroupAuthorized')->willReturnCallback(static fn (): bool => $world['groupOk']);
		$authorizer->method('isAdmin')->willReturnCallback(static fn (): bool => $world['admin']);

		$guards = $this->createMock(GuardRegistry::class);
		$guards->method('evaluateAll')->willReturnCallback(static fn (): array => $world['guards']);
		$guards->method('knows')->willReturn(false);

		$results = $this->createMock(CaseResultWriter::class);
		$results->method('isFinalStatus')->willReturnCallback(static fn (): bool => $world['final']);
		$results->method('resolveClosingResult')->willReturn('result-1');
		$results->method('archivalFuture')->willReturn(['archiveNomination' => 'vernietigen']);

		$checklist = $this->createMock(StatusChecklist::class);
		$checklist->method('actionsFor')->willReturn([['type' => 'createTask', 'title' => 'Dossier controleren']]);

		$dispatcher = $this->createMock(SideEffectDispatcher::class);
		$dispatcher->method('dispatch')->willReturnCallback(
			function (array $actions, array $case, array $transitionContext): array {
				$this->calls[] = ['dispatch', $actions, $transitionContext];
				return [['type' => 'createTask', 'ok' => true]];
			}
		);

		$handSet = $this->createMock(ProcessOwnedStatusRule::class);
		$handSet->method('requireHandSetAllowed')->willReturnCallback(
			static function () use (&$world): void {
				if ($world['handSetRefused'] !== null) {
					throw $world['handSetRefused'];
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new StatusTransitionService(
			templateLoader: $loader,
			guardRegistry: $guards,
			sideEffectDispatcher: $dispatcher,
			store: $store,
			authorizer: $authorizer,
			specReader: new TransitionSpecReader(),
			userSession: $this->createMock(IUserSession::class),
			logger: $logger,
			resultWriter: $results,
			statusChecklist: $checklist,
			declarations: $statuses,
			declaredMoves: $moves,
			offered: $offered,
			processOwnedStatus: $handSet,
		);
	}//end engine()

	/**
	 * The rule a refused move names, or the message of another failure.
	 *
	 * @param \Closure $move The call on the engine.
	 *
	 * @return string
	 */
	private function refusalOf(\Closure $move): string {
		try {
			$move();
		} catch (RefusedException $refused) {
			return $refused->getRule().' '.$refused->getStatus();
		} catch (RuntimeException $failure) {
			return $failure->getMessage();
		}

		return 'went through';
	}//end refusalOf()

	/**
	 * The names of the calls made, in order.
	 *
	 * @return array<int, string>
	 */
	private function callNames(): array {
		return array_column($this->calls, 0);
	}//end callNames()

	/**
	 * A move with everything against it is refused for the first reason in a fixed order: the status
	 * it starts from, an open dependency, four eyes, the group, the guards. Nothing is written on any.
	 *
	 * Control: with nothing against it the same move goes through.
	 *
	 * @return void
	 */
	public function testTheRefusalsComeInAFixedOrder(): void {
		$case   = ['id' => 'case-1', 'caseType' => 'ct-1', 'status' => 'st-open', '@self' => ['version' => 3]];
		$fixes  = [
			'transition' => self::TRANSITION,
			'withheld'   => [],
			'fourEyes'   => null,
			'groupOk'    => true,
			'guards'     => [['type' => 'role', 'passed' => true]],
		];
		$this->world = array_merge(
			$this->world,
			[
				'transition' => (['fromStatus' => 'st-other'] + self::TRANSITION),
				'withheld'   => ['the advice of the fire brigade', 'the fee'],
				'fourEyes'   => ['reason' => 'same-person'],
				'groupOk'    => false,
				'guards'     => [['type' => 'role', 'passed' => false]],
			]
		);

		$seen = [];
		foreach ($fixes as $key => $fixed) {
			$this->world['cases'] = [$case, $case];
			$seen[]               = $this->refusalOf(fn () => $this->engine()->execute('case-1', 't1', null, 'jan'));
			$this->world[$key]    = $fixed;
		}

		$this->assertSame(
			[
				'transition-from-status-mismatch 409',
				'transition-dependency-open 409',
				'transition-four-eyes 403',
				'transition-unauthorized 403',
				'guard_failed',
			],
			$seen
		);
		$this->assertSame([], $this->calls, 'a refused move writes nothing');

		$this->world['cases'] = [$case, $case];
		$this->assertSame('went through', $this->refusalOf(fn () => $this->engine()->execute('case-1', 't1', null, 'jan')));
	}//end testTheRefusalsComeInAFixedOrder()

	/**
	 * The open dependency is named in the sentence, the first one only, and failed guards travel with
	 * their exception.
	 *
	 * @return void
	 */
	public function testARefusalSaysWhatItWaitsOn(): void {
		$this->world['withheld'] = ['the advice of the fire brigade', 'the fee'];
		try {
			$this->engine()->execute('case-1', 't1', null, 'jan');
			$this->fail('an open dependency must refuse');
		} catch (RefusedException $refused) {
			$this->assertSame('This move is waiting on the advice of the fire brigade.', $refused->getSentence());
		}

		$this->setUp();
		$this->world['guards'] = [['type' => 'role', 'passed' => true], ['type' => 'field', 'passed' => false]];
		try {
			$this->engine()->execute('case-1', 't1', null, 'jan');
			$this->fail('a failed guard must refuse');
		} catch (GuardFailedException $failed) {
			$this->assertSame([['type' => 'field', 'passed' => false]], $failed->getFailedGuards());
		}
	}//end testARefusalSaysWhatItWaitsOn()

	/**
	 * A case, a transition or a target status that is not there stops the move by name.
	 *
	 * @return void
	 */
	public function testWhatIsMissingStopsTheMove(): void {
		$this->world['cases'] = [];
		$noCase               = $this->refusalOf(fn () => $this->engine()->execute('case-1', 't1', null, 'jan'));

		$this->setUp();
		$this->world['transition'] = null;
		$noTransition              = $this->refusalOf(fn () => $this->engine()->execute('case-1', 't1', null, 'jan'));

		$this->setUp();
		$this->world['transition'] = ['id' => 't1', 'fromStatus' => 'st-open'];
		$noTarget                  = $this->refusalOf(fn () => $this->engine()->execute('case-1', 't1', null, 'jan'));

		$this->assertSame(['case_not_found', 'transition_not_found', 'transition_missing_to_status'], [$noCase, $noTransition, $noTarget]);
		$this->assertSame([], $this->calls);
	}//end testWhatIsMissingStopsTheMove()

	/**
	 * A case that changed between the read and the save is a conflict: another version, another
	 * status, or gone. Nothing is written.
	 *
	 * @return void
	 */
	public function testAChangeBetweenReadAndSaveIsAConflict(): void {
		$case = $this->world['cases'][0];

		$this->world['cases'] = [$case, (['@self' => ['version' => 4]] + $case)];
		$version              = $this->refusalOf(fn () => $this->engine()->execute('case-1', 't1', null, 'jan'));

		$this->world['cases'] = [$case, (['status' => 'st-elsewhere'] + $case)];
		$status               = $this->refusalOf(fn () => $this->engine()->execute('case-1', 't1', null, 'jan'));

		$this->world['cases'] = [$case];
		$gone                 = $this->refusalOf(fn () => $this->engine()->execute('case-1', 't1', null, 'jan'));

		$this->assertSame(['transition-conflict 409', 'transition-conflict 409', 'case_not_found'], [$version, $status, $gone]);
		$this->assertSame([], $this->calls);
	}//end testAChangeBetweenReadAndSaveIsAConflict()

	/**
	 * A closing move writes its result, end date, archival future, stamp, status and the version it
	 * read in ONE save, then retimes, records, dispatches the checklist and writes the outcome back.
	 *
	 * @return void
	 */
	public function testAClosingMoveIsOneSaveThenTheRest(): void {
		$this->world['final']          = true;
		$this->world['cases'][1]      += ['endDate' => '2026-10-01T09:00:00+02:00'];

		$answer = $this->engine()->execute('case-1', 't1', 'Klaar.', 'jan', 'rt-1');

		$this->assertSame(['saveCase', 'retime', 'writeStatusRecord', 'dispatch', 'updateStatusRecord'], $this->callNames());
		$this->assertSame(
			[
				'id'                => 'case-1',
				'caseType'          => 'ct-1',
				'status'            => 'st-closed',
				'@self'             => ['version' => 3],
				'endDate'           => '2026-10-01',
				'result'            => 'result-1',
				'archiveNomination' => 'vernietigen',
				'statusEnteredAt'   => 'stamped for st-closed',
			],
			$this->calls[0][1]
		);
		$this->assertSame(['retime', 'case-1', 'st-closed'], $this->calls[1]);
		$this->assertSame(
			[
				'caseId'             => 'case-1',
				'toStatus'           => 'st-closed',
				'fromStatus'         => 'st-open',
				'label'              => 'Besluiten',
				'comment'            => 'Klaar.',
				'evaluatedGuards'    => [['type' => 'role', 'passed' => true]],
				'noWorkflowTemplate' => false,
				'actor'              => 'jan',
			],
			$this->calls[2][1]
		);
		$this->assertSame([['type' => 'createTask', 'title' => 'Dossier controleren']], $this->calls[3][1]);
		$this->assertSame(
			['fromStatus' => 'st-open', 'toStatus' => 'st-closed', 'transitionLabel' => 'Besluiten', 'userId' => 'jan', 'statusRecordUuid' => 'rec-1'],
			$this->calls[3][2]
		);
		$this->assertSame(
			[
				'status'            => 'ok',
				'statusRecord'      => ['id' => 'rec-1', 'dispatchedActions' => [['type' => 'createTask', 'ok' => true]], 'updated' => true],
				'dispatchedActions' => [['type' => 'createTask', 'ok' => true]],
				'failedActions'     => [],
				'version'           => 4,
			],
			$answer
		);
	}//end testAClosingMoveIsOneSaveThenTheRest()

	/**
	 * The version the engine read travels with the save, also for a case that carries it at the top
	 * level and has no `@self` block.
	 *
	 * @return void
	 */
	public function testTheVersionReadTravelsWithTheSave(): void {
		$case                 = ['id' => 'case-1', 'caseType' => 'ct-1', 'status' => 'st-open', 'version' => 7];
		$this->world['cases'] = [$case, $case];

		$this->engine()->execute('case-1', 't1', null, 'jan');

		$this->assertSame(['version' => 7], $this->calls[0][1]['@self']);
	}//end testTheVersionReadTravelsWithTheSave()

	/**
	 * A save that fails stops the move there: no timer, no record, no side effect.
	 *
	 * @return void
	 */
	public function testNothingFollowsASaveThatFails(): void {
		$this->world['saveThrows'] = new RuntimeException('store down');

		$this->assertSame('store down', $this->refusalOf(fn () => $this->engine()->execute('case-1', 't1', null, 'jan')));
		$this->assertSame([], $this->calls);
	}//end testNothingFollowsASaveThatFails()

	/**
	 * A record whose dispatched actions cannot be written back is logged at error; the move answers
	 * with the record as it was written first.
	 *
	 * @return void
	 */
	public function testARecordThatCannotBeUpdatedIsLoggedAndTheMoveAnswers(): void {
		$this->world['updateThrows'] = new RuntimeException('store down');

		$answer = $this->engine()->execute('case-1', 't1', null, 'jan');

		$this->assertSame('ok', $answer['status']);
		$this->assertSame(['id' => 'rec-1', 'dispatchedActions' => [['type' => 'createTask', 'ok' => true]]], $answer['statusRecord']);
		$this->assertSame(['StatusTransitionService: dispatchedActions persist failed'], $this->errors);
	}//end testARecordThatCannotBeUpdatedIsLoggedAndTheMoveAnswers()

	/**
	 * A free-form move is refused to anyone but an administrator, where the process owns the status,
	 * and for a status of another case type, in that order and before any write.
	 *
	 * Control: an administrator on a hand-set case type moves the case, stamped, without a closing result.
	 *
	 * @return void
	 */
	public function testTheFreeFormRefusalsAndTheFreeFormMove(): void {
		$case                          = $this->world['cases'][0];
		$this->world['cases']          = array_fill(0, 4, $case);
		$this->world['admin']          = false;
		$this->world['handSetRefused'] = new RuntimeException('process_owned');
		$this->world['foreignStatus']  = new RuntimeException('status_not_of_case_type');

		$seen = [];
		foreach (['admin' => true, 'handSetRefused' => null, 'foreignStatus' => null] as $key => $fixed) {
			$seen[]            = $this->refusalOf(fn () => $this->engine()->executeFreeForm('case-1', 'st-elsewhere', null, 'jan'));
			$this->world[$key] = $fixed;
		}

		$this->assertSame(['forbidden_admin_only', 'process_owned', 'status_not_of_case_type'], $seen);
		$this->assertSame([], $this->calls);

		$this->world['final'] = true;
		$answer               = $this->engine()->executeFreeForm('case-1', 'st-elsewhere', 'Hersteld.', 'jan');

		$this->assertSame(['saveCase', 'retime', 'writeStatusRecord', 'dispatch'], $this->callNames());
		$this->assertSame('st-elsewhere', $this->calls[0][1]['status']);
		$this->assertSame('stamped for st-elsewhere', $this->calls[0][1]['statusEnteredAt']);
		$this->assertArrayNotHasKey('result', $this->calls[0][1], 'a free-form move records no closing result');
		$this->assertSame('Free-form transition', $this->calls[2][1]['label']);
		$this->assertTrue($this->calls[2][1]['noWorkflowTemplate']);
		$this->assertSame(['status', 'statusRecord', 'dispatchedActions', 'failedActions'], array_keys($answer));
	}//end testTheFreeFormRefusalsAndTheFreeFormMove()

	/**
	 * The available transitions: empty without a case; the status facts and an empty `withheld`
	 * without a workflow; the offered and the withheld moves with one.
	 *
	 * @return void
	 */
	public function testTheShapeOfTheAvailableTransitions(): void {
		$current = [
			'statusId'          => 'st-open',
			'statusName'        => 'name of st-open',
			'statusColour'      => '#123456',
			'statusDescription' => 'What it means.',
			'waitingOn'         => 'us',
			'dwell'             => ['days' => 2],
		];

		$with = $this->engine()->getAvailableTransitions('case-1', 'jan');

		$this->setUp();
		$this->world['template'] = null;
		$without                 = $this->engine()->getAvailableTransitions('case-1', 'jan');

		$this->setUp();
		$this->world['template'] = ['transitions' => 'not a list'];
		$broken                  = $this->engine()->getAvailableTransitions('case-1', 'jan');

		$this->setUp();
		$this->world['cases'] = [];
		$noCase               = $this->engine()->getAvailableTransitions('case-1', 'jan');

		$this->assertSame(
			['transitions' => [['id' => 't1']], 'withheld' => [['id' => 't2', 'reasons' => ['advice']]], 'current' => $current, 'derivation' => null],
			$with
		);
		$this->assertSame(['transitions' => [], 'withheld' => [], 'current' => $current, 'derivation' => null], $without);
		$this->assertSame($without, $broken);
		$this->assertSame(['transitions' => [], 'current' => []], $noCase);
	}//end testTheShapeOfTheAvailableTransitions()
}//end class
