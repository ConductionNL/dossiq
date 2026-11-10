<?php

/**
 * Golden outcomes of delegating a decision to the decision app.
 *
 * Pins ContractDecisionDelegationService's allowed outcome (every field of
 * the event it raises, the decision id it returns, the info line) and each
 * fail-closed refusal (the dispatch throws; the event comes back unhandled;
 * it comes back handled without an id), with the exact messages and log
 * lines, for both public entry points.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Decidiq\Event\DecisionRequestedEvent;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\ContractDecisionDelegationService
 */
class DecisionDelegationGoldenTest extends TestCase {

	/**
	 * The log lines, in order.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The event the dispatcher saw.
	 *
	 * @var DecisionRequestedEvent|null
	 */
	private ?DecisionRequestedEvent $event = null;

	/**
	 * Allowed: a contract decision raises an event carrying every field.
	 *
	 * @return void
	 */
	public function testAContractDecisionRaisesTheFullEvent(): void {
		$ref = $this->service(answer: ['handled' => true, 'id' => 'd-1'])->raiseContractDecision(
			caseRef: 'case-1',
			contractRef: 'contract-1',
			decisionType: 'contract-renewal',
			subject: ['subjectRegister' => 'r', 'subjectSchema' => 's', 'subjectLabel' => 'L'],
			mandateContext: ['requestedBy' => 'u1', 'mandateRole' => 'm'],
		);

		self::assertSame('d-1', $ref);
		self::assertSame(
			['procest', 'r', 's', 'contract-1', 'L', 'contract-renewal', 'u1', ['title' => 'L', 'context' => ['requestedBy' => 'u1', 'mandateRole' => 'm']], 'case-1', 'case-1'],
			$this->fieldsOf()
		);
		self::assertSame(
			[['info', 'ContractDecisionDelegationService: decidesk Decision raised via event', ['externalReference' => 'case-1', 'decisionType' => 'contract-renewal', 'decisionRef' => 'd-1']]],
			$this->logged
		);
	}//end testAContractDecisionRaisesTheFullEvent()

	/**
	 * Allowed: a generic decision takes its actor from the context.
	 *
	 * @return void
	 */
	public function testAGenericDecisionTakesItsActorFromTheContext(): void {
		$this->service(answer: ['handled' => true, 'id' => 'd-2'])->raiseDecision(
			decisionType: 'advice',
			externalReference: 'ref-9',
			subject: ['subjectId' => 'a-1'],
			context: ['actorId' => 'u2'],
		);

		self::assertSame(['procest', '', '', 'a-1', '', 'advice', 'u2', ['title' => '', 'context' => ['actorId' => 'u2']], 'ref-9', 'ref-9'], $this->fieldsOf());
	}//end testAGenericDecisionTakesItsActorFromTheContext()

	/**
	 * Refused: each fail-closed path, with its message and log line.
	 *
	 * @return void
	 */
	public function testEveryFailClosedPathKeepsItsShape(): void {
		$cases = [
			'dispatch throws' => [
				['throws' => true],
				'Decision service error: listener exploded',
				['error', 'ContractDecisionDelegationService: DecisionRequestedEvent dispatch failed', ['externalReference' => 'ref-1', 'error' => 'listener exploded']],
			],
			'unhandled' => [
				['handled' => false, 'id' => 'd-1'],
				'Decision service unavailable: decidesk did not handle the decision request. Decision cannot proceed.',
				['error', 'ContractDecisionDelegationService: decidesk did not handle the decision request; failing closed', ['externalReference' => 'ref-1', 'decisionType' => 'advice', 'handled' => false]],
			],
			'no id' => [
				['handled' => true, 'id' => ''],
				'Decision service unavailable: decidesk did not handle the decision request. Decision cannot proceed.',
				['error', 'ContractDecisionDelegationService: decidesk did not handle the decision request; failing closed', ['externalReference' => 'ref-1', 'decisionType' => 'advice', 'handled' => true]],
			],
			'null id' => [
				['handled' => true, 'id' => null],
				'Decision service unavailable: decidesk did not handle the decision request. Decision cannot proceed.',
				['error', 'ContractDecisionDelegationService: decidesk did not handle the decision request; failing closed', ['externalReference' => 'ref-1', 'decisionType' => 'advice', 'handled' => true]],
			],
		];

		foreach ($cases as $label => [$answer, $message, $log]) {
			$this->logged = [];
			try {
				$this->service(answer: $answer)->raiseDecision(decisionType: 'advice', externalReference: 'ref-1', subject: []);
				self::fail($label . ' must fail closed');
			} catch (RuntimeException $e) {
				self::assertSame($message, $e->getMessage(), $label);
				self::assertSame([$log], $this->logged, $label);
			}
		}
	}//end testEveryFailClosedPathKeepsItsShape()

	/**
	 * Reading a decision back: the refusal before the bus and the dispatch
	 * failure are logged with these exact lines; a found decision is not logged.
	 *
	 * @return void
	 */
	public function testAStateReadLogsWhyItCouldNotRead(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event): void {
				throw new RuntimeException('bus down');
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		$service = new ContractDecisionDelegationService($dispatcher, $logger);
		$noActor = $service->readDecisionState(decisionId: ' d-1 ', actorId: ' ');
		$thrown = $service->readDecisionState(decisionId: 'd-1', actorId: 'alice');

		self::assertSame(['state' => ContractDecisionDelegationService::DECISION_STATE_UNREADABLE, 'status' => '', 'envelope' => []], $noActor);
		self::assertSame($noActor, $thrown);
		self::assertSame(
			[
				['warning', 'ContractDecisionDelegationService: cannot read a decision state', ['decisionRef' => 'd-1', 'hasActor' => false, 'reason' => 'no decision id or no actor']],
				['error', 'ContractDecisionDelegationService: DecisionStateRequestedEvent dispatch failed', ['decisionRef' => 'd-1', 'error' => 'bus down']],
			],
			$this->logged
		);
	}//end testAStateReadLogsWhyItCouldNotRead()

	/**
	 * The raised event's fields, in constructor order.
	 *
	 * @return array<int, mixed>
	 */
	private function fieldsOf(): array {
		$e = $this->event;
		self::assertNotNull($e);

		return [
			$e->getSourceApp(), $e->getSubjectRegister(), $e->getSubjectSchema(), $e->getSubjectId(), $e->getSubjectLabel(),
			$e->getDecisionType(), $e->getActorId(), $e->getPayload(), $e->getExternalReference(), $e->getCorrelationId(),
		];
	}//end fieldsOf()

	/**
	 * The service over a dispatcher that answers as told and a recording logger.
	 *
	 * @param array<string, mixed> $answer `throws`, `handled`, `id`.
	 *
	 * @return ContractDecisionDelegationService
	 */
	private function service(array $answer): ContractDecisionDelegationService {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($answer): void {
				$this->event = $event;
				if (($answer['throws'] ?? false) === true) {
					throw new RuntimeException('listener exploded');
				}

				$event->setHandled($answer['handled']);
				$event->setDecisionId($answer['id']);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new ContractDecisionDelegationService($dispatcher, $logger);
	}//end service()
}//end class
