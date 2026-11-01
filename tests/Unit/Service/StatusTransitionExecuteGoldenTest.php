<?php

/**
 * Golden test for executing a declared status transition.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use ArrayObject;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\ProcessOwnedStatusRule;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseDeclarations;
use OCA\Dossiq\Service\Transitions\CaseMoveLedger;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every collaborator call of execute(), the answer, and for which user each check runs.
 *
 * @covers \OCA\Dossiq\Service\StatusTransitionService
 * @covers \OCA\Dossiq\Exception\RefusedException
 * @covers \OCA\Dossiq\Service\Transitions\GuardFailedException
 */
class StatusTransitionExecuteGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	private const ACTING = 'uid-anna';

	/**
	 * Every call, in order.
	 *
	 * @var ArrayObject
	 */
	private ArrayObject $trace;

	/**
	 * What the collaborators answer.
	 *
	 * @var array<string, mixed>
	 */
	private array $world = [];

	/**
	 * Let a double's method record its named arguments and answer from a callback.
	 *
	 * @param MockObject $double The double.
	 * @param string     $class  The doubled class.
	 * @param string     $method The method.
	 * @param \Closure   $answer What it answers, given the named arguments.
	 *
	 * @return void
	 */
	private function record(MockObject $double, string $class, string $method, \Closure $answer): void {
		$names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod($class, $method))->getParameters());
		$double->method($method)->willReturnCallback(
			function (mixed ...$values) use ($names, $method, $answer): mixed {
				$args = array_combine(array_slice($names, 0, count($values)), $values);
				$this->trace->append([$method, $args]);
				return $answer($args);
			}
		);
	}//end record()

	/**
	 * Build the engine over the world.
	 *
	 * @return StatusTransitionService
	 */
	private function engine(): StatusTransitionService {
		$world = &$this->world;

		$loader = $this->createMock(WorkflowTemplateLoader::class);
		$this->record($loader, WorkflowTemplateLoader::class, 'getTransitionForCase', static fn (): ?array => $world['transition']);

		$guards = $this->createMock(GuardRegistry::class);
		$this->record($guards, GuardRegistry::class, 'knows', static fn (): bool => true);
		$this->record($guards, GuardRegistry::class, 'evaluateAll', static fn (array $a): array => $world['guards']);

		$authorizer = $this->createMock(TransitionAuthorizer::class);
		$this->record($authorizer, TransitionAuthorizer::class, 'isTransitionGroupAuthorized', static fn (array $a): bool => in_array($a['userId'], $world['groupMembers'], true));

		$spec = $this->createMock(TransitionSpecReader::class);
		$this->record($spec, TransitionSpecReader::class, 'guardsWithImplicit', static fn (array $a): array => [['type' => 'implicit']]);
		$this->record($spec, TransitionSpecReader::class, 'extractActions', static fn (): array => [['type' => 'notify']]);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use (&$world): ?IUser {
				$this->trace->append(['getUser']);
				if ($world['sessionUser'] === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($world['sessionUser']);
				return $user;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace->append(['log', $level, $message, $context]);
				}
			);
		}

		$declarations = $this->createMock(CaseDeclarations::class);
		$this->record(
			$declarations,
			CaseDeclarations::class,
			'assertOpen',
			static function () use (&$world): mixed {
				if ($world['closedBy'] !== null) {
					throw $world['closedBy'];
				}

				return null;
			}
		);
		$this->record($declarations, CaseDeclarations::class, 'stamp', static fn (array $a): array => ($a['case'] + ['stamped' => $a['toStatus']]));
		$this->record($declarations, CaseDeclarations::class, 'retime', static fn (): mixed => null);
		$this->record($declarations, CaseDeclarations::class, 'checklistActions', static fn (array $a): array => [['type' => 'createTask', 'for' => $a['actor']]]);

		$ledger = $this->createMock(CaseMoveLedger::class);
		$this->record($ledger, CaseMoveLedger::class, 'load', static fn (): ?array => $world['case']);
		$this->record(
			$ledger,
			CaseMoveLedger::class,
			'reloadUnchanged',
			static function (array $a) use (&$world): array {
				if ($world['raced'] === true) {
					throw new RuntimeException('case_changed_concurrently');
				}

				return ($world['case'] + ['reloaded' => true]);
			}
		);
		$this->record(
			$ledger,
			CaseMoveLedger::class,
			'close',
			static function (array $a) use (&$world): array {
				if ($world['closeRefused'] === true) {
					throw new RuntimeException('result_type_required');
				}

				return ($a['case'] + ['closedWith' => $a['resultTypeId']]);
			}
		);
		$this->record($ledger, CaseMoveLedger::class, 'save', static fn (array $a): array => [($a['case'] + ['status' => $a['toStatus'], 'saved' => true]), ($a['readVersion'] + 1)]);
		$this->record($ledger, CaseMoveLedger::class, 'record', static fn (): array => ['id' => 'rec-1']);
		$this->record($ledger, CaseMoveLedger::class, 'dispatch', static fn (): array => $world['dispatched']);
		$this->record($ledger, CaseMoveLedger::class, 'writeBack', static fn (array $a): array => ($a['record'] + ['dispatchedActions' => $a['dispatched']]));

		return new StatusTransitionService(
			templateLoader: $loader,
			guardRegistry: $guards,
			authorizer: $authorizer,
			specReader: $spec,
			userSession: $session,
			logger: $logger,
			processOwnedStatus: $this->createMock(ProcessOwnedStatusRule::class),
			declarations: $declarations,
			ledger: $ledger,
		);
	}//end engine()

	/**
	 * Run one execute() and answer the result or the refusal, plus every call.
	 *
	 * @param array<string, mixed> $world Overrides of the world.
	 * @param array<string, mixed> $args  Overrides of the arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(array $world = [], array $args = []): array {
		$this->trace = new ArrayObject();
		$this->world = array_merge(
			[
				'case' => ['id' => 'case-1', 'status' => 'st-open', 'caseType' => 'ct-1', '@self' => ['version' => 4]],
				'transition' => ['id' => 't1', 'label' => 'Besluiten', 'fromStatus' => 'st-open', 'toStatus' => 'st-closed', 'guards' => []],
				'guards' => [['type' => 'role', 'passed' => true]],
				'groupMembers' => [self::ACTING],
				'sessionUser' => self::ACTING,
				'closedBy' => null,
				'raced' => false,
				'closeRefused' => false,
				'dispatched' => [['type' => 'createTask', 'ok' => true], ['type' => 'notify', 'ok' => true]],
			],
			$world
		);
		$arguments = array_merge(['caseId' => 'case-1', 'transitionId' => 't1', 'comment' => 'Klaar', 'resultTypeId' => 'rt-1'], $args);

		try {
			$outcome = ['result' => $this->engine()->execute(...$arguments)];
		} catch (GuardFailedException $e) {
			$outcome = ['guardFailed' => $e->getFailedGuards()];
		} catch (RefusedException $e) {
			$outcome = ['refused' => [$e->getRule(), $e->getSentence(), $e->getStatus()]];
		} catch (RuntimeException $e) {
			$outcome = ['error' => [get_class($e), $e->getMessage()]];
		}

		return $outcome + ['calls' => $this->trace->getArrayCopy()];
	}//end observe()

	/**
	 * Every path through execute(), recorded on the code before the split.
	 *
	 * @return void
	 */
	public function testGoldenExecute(): void {
		$observed = [
			'a move by the session user' => $this->observe(),
			'a move with an explicit user' => $this->observe(['groupMembers' => ['uid-bob']], ['userId' => 'uid-bob']),
			'an empty explicit user falls back to the session' => $this->observe([], ['userId' => '']),
			'nobody signed in' => $this->observe(['sessionUser' => null, 'groupMembers' => ['']]),
			'a session user outside the groups' => $this->observe(['groupMembers' => ['uid-bob']]),
			'an explicit user outside the groups while the session user is in them' => $this->observe([], ['userId' => 'uid-mallory']),
			'a missing case' => $this->observe(['case' => null]),
			'an unknown transition' => $this->observe(['transition' => null]),
			'a move from another status' => $this->observe(['transition' => ['id' => 't1', 'fromStatus' => 'st-other', 'toStatus' => 'st-closed']]),
			'a move from any status' => $this->observe(['transition' => ['id' => 't1', 'toStatus' => 'st-closed']]),
			'a move the case holds closed' => $this->observe(['closedBy' => new RefusedException(rule: 'advice-pending', sentence: 'Wacht op advies.', status: RefusedException::STATUS_REFUSED)]),
			'a failing guard' => $this->observe(['guards' => [['type' => 'role', 'passed' => true], ['type' => 'doc', 'passed' => false]]]),
			'a move without a target' => $this->observe(['transition' => ['id' => 't1', 'fromStatus' => 'st-open', 'label' => 'Leeg']]),
			'a case without a status' => $this->observe(['case' => ['id' => 'case-1', 'version' => 7], 'transition' => ['id' => 't1', 'toStatus' => 'st-closed']]),
			'a case with a top-level version' => $this->observe(['case' => ['id' => 'case-1', 'status' => 'st-open', 'version' => '9']]),
			'a case with both versions' => $this->observe(['case' => ['id' => 'case-1', 'status' => 'st-open', 'version' => 11, '@self' => ['version' => 5]]]),
			'a concurrent change' => $this->observe(['raced' => true]),
			'a close without a result' => $this->observe(['closeRefused' => true], ['resultTypeId' => null]),
			'no comment' => $this->observe([], ['comment' => null]),
			'a failed action' => $this->observe(['dispatched' => [['type' => 'createTask', 'ok' => false, 'error' => 'no actor'], 'not a row', ['type' => 'notify'], ['ok' => false]]]),
		];

		$this->assertMatchesGolden('status-transition-execute', $observed);
	}//end testGoldenExecute()
}//end class
