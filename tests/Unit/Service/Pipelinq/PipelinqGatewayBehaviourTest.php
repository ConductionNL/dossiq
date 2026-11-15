<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the gateway answers for each shape a pipelinq can come back in, and
 * how often it says so in the log.
 *
 * @covers \OCA\Dossiq\Service\Pipelinq\PipelinqGateway
 */
class PipelinqGatewayBehaviourTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A gateway over a container that answers the given values.
	 *
	 * @param array<string, mixed> $services Keyed by class name.
	 *
	 * @return PipelinqGateway The gateway under test.
	 */
	private function gateway(array $services = []): PipelinqGateway {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): mixed {
				if (array_key_exists($id, $services) === false) {
					throw new RuntimeException("nothing answers to {$id}");
				}

				return $services[$id];
			}
		);

		return new PipelinqGateway($container, $this->logger);
	}//end gateway()

	/**
	 * A ticket leaf that records what it was asked and answers a fixed value.
	 *
	 * @param mixed $answer What both ticket methods answer.
	 *
	 * @return object The fake leaf; its `calls` property holds the calls.
	 */
	private function ticketLeaf(mixed $answer): object {
		return new class($answer) {
			/** @var list<array{string, array<string, mixed>}> */
			public array $calls = [];

			public function __construct(private mixed $answer) {
			}

			public function openByMasterEntity(array $refs): mixed {
				$this->calls[] = ['openByMasterEntity', ['refs' => $refs]];
				return $this->answer;
			}

			public function byMasterEntity(array $refs, string $status): mixed {
				$this->calls[] = ['byMasterEntity', ['refs' => $refs, 'status' => $status]];
				return $this->answer;
			}
		};
	}//end ticketLeaf()

	public function testAMissingCapabilityIsLoggedOnceNoMatterHowOftenItIsAsked(): void {
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				$this->stringContains('pipelinq is not installed on this instance'),
				['class' => PipelinqGateway::PARTY_KINDS]
			);

		$gateway = $this->gateway();

		$this->assertNull($gateway->service(class: PipelinqGateway::PARTY_KINDS));
		$this->assertNull($gateway->service(class: PipelinqGateway::PARTY_KINDS));
		$this->assertFalse($gateway->ask(class: PipelinqGateway::PARTY_KINDS, method: 'kindsFor')['answered']);
	}//end testAMissingCapabilityIsLoggedOnceNoMatterHowOftenItIsAsked()

	public function testEachMissingCapabilityIsLoggedSeparately(): void {
		$this->logger->expects($this->exactly(2))->method('debug');

		$gateway = $this->gateway();
		$gateway->service(class: PipelinqGateway::PARTY_KINDS);
		$gateway->service(class: PipelinqGateway::PROGRAMMES);
		$gateway->service(class: PipelinqGateway::PROGRAMMES);
	}//end testEachMissingCapabilityIsLoggedSeparately()

	public function testAnExistingClassTheContainerRefusesReportsTheContainersReason(): void {
		$this->logger->expects($this->once())
			->method('debug')
			->with($this->stringContains('nothing answers to ' . self::class), ['class' => self::class]);

		$this->assertNull($this->gateway()->service(class: self::class));
	}//end testAnExistingClassTheContainerRefusesReportsTheContainersReason()

	public function testAContainerAnswerThatIsNotAnObjectIsRefused(): void {
		$this->logger->expects($this->once())
			->method('debug')
			->with($this->stringContains('not an object'), ['class' => PipelinqGateway::TICKETS]);

		$gateway = $this->gateway([PipelinqGateway::TICKETS => 'a string']);

		$this->assertNull($gateway->service(class: PipelinqGateway::TICKETS));
		$this->assertNull($gateway->openTicketsByMasterEntity(refs: ['m1']));
	}//end testAContainerAnswerThatIsNotAnObjectIsRefused()

	public function testAMissingMethodIsLoggedWithItsName(): void {
		$this->logger->expects($this->once())
			->method('debug')
			->with($this->stringContains('has no create()'), ['class' => PipelinqGateway::CONTACT_MOMENTS]);

		$service = new class {
			public function list(): array {
				return [];
			}
		};

		$this->assertNull(
			$this->gateway([PipelinqGateway::CONTACT_MOMENTS => $service])
				->service(class: PipelinqGateway::CONTACT_MOMENTS, methods: ['create'])
		);
	}//end testAMissingMethodIsLoggedWithItsName()

	public function testAServiceWithoutRequestedMethodsIsReturnedAsIs(): void {
		$service = new \stdClass();

		$this->assertSame(
			$service,
			$this->gateway([PipelinqGateway::PARTY_PANEL => $service])->service(class: PipelinqGateway::PARTY_PANEL)
		);
	}//end testAServiceWithoutRequestedMethodsIsReturnedAsIs()

	public function testAskPassesNamedArgumentsAndAnswersTheValue(): void {
		$service = new class {
			public function resolve(string $partyId, string $act): string {
				return "{$partyId}:{$act}";
			}
		};

		$answer = $this->gateway([PipelinqGateway::PARTY_INDICATORS => $service])->ask(
			class: PipelinqGateway::PARTY_INDICATORS,
			method: 'resolve',
			arguments: ['act' => 'close', 'partyId' => 'p7'],
			fallback: 'unused',
		);

		$this->assertSame(['answered' => true, 'value' => 'p7:close', 'reason' => ''], $answer);
	}//end testAskPassesNamedArgumentsAndAnswersTheValue()

	public function testAMissingServiceAnswersTheFixedReason(): void {
		$answer = $this->gateway()->ask(
			class: PipelinqGateway::PROGRAMMES,
			method: 'read',
			fallback: 42,
		);

		$this->assertSame(
			[
				'answered' => false,
				'value' => 42,
				'reason' => 'pipelinq does not serve this capability on this instance',
			],
			$answer
		);
	}//end testAMissingServiceAnswersTheFixedReason()

	public function testAThrowingCallIsLoggedAsAWarningWithClassAndMethod(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('the call failed, so the fallback answers and nothing here is refused: boom'),
				['class' => PipelinqGateway::SURVEY_DISPATCH, 'method' => 'onInteractionCompleted']
			);

		$service = new class {
			public function onInteractionCompleted(): void {
				throw new RuntimeException('boom');
			}
		};

		$answer = $this->gateway([PipelinqGateway::SURVEY_DISPATCH => $service])->ask(
			class: PipelinqGateway::SURVEY_DISPATCH,
			method: 'onInteractionCompleted',
			fallback: 'fb',
		);

		$this->assertSame(['answered' => false, 'value' => 'fb', 'reason' => 'boom'], $answer);
	}//end testAThrowingCallIsLoggedAsAWarningWithClassAndMethod()

	public function testOpenTicketsAreReadByReindexedReferences(): void {
		$leaf = $this->ticketLeaf(['status' => 200, 'tickets' => ['m1' => [['id' => 't1']]]]);

		$tickets = $this->gateway([PipelinqGateway::TICKETS => $leaf])
			->openTicketsByMasterEntity(refs: [3 => 'm1', 9 => 'm2']);

		$this->assertSame(['m1' => [['id' => 't1']]], $tickets);
		$this->assertSame([['openByMasterEntity', ['refs' => ['m1', 'm2']]]], $leaf->calls);
	}//end testOpenTicketsAreReadByReindexedReferences()

	public function testTheOpenStatusReadsThroughTheOpenTicketsCall(): void {
		$leaf = $this->ticketLeaf(['status' => 200, 'tickets' => []]);

		$tickets = $this->gateway([PipelinqGateway::TICKETS => $leaf])
			->ticketsByMasterEntity(refs: ['m1'], status: 'open');

		$this->assertSame([], $tickets);
		$this->assertSame([['openByMasterEntity', ['refs' => ['m1']]]], $leaf->calls);
	}//end testTheOpenStatusReadsThroughTheOpenTicketsCall()

	public function testAnyOtherStatusIsPassedToTheStatusAwareCall(): void {
		$leaf = $this->ticketLeaf(['status' => 200, 'tickets' => ['m1' => [['id' => 'closed']]]]);

		$tickets = $this->gateway([PipelinqGateway::TICKETS => $leaf])
			->ticketsByMasterEntity(refs: [5 => 'm1'], status: 'closed');

		$this->assertSame(['m1' => [['id' => 'closed']]], $tickets);
		$this->assertSame([['byMasterEntity', ['refs' => ['m1'], 'status' => 'closed']]], $leaf->calls);
	}//end testAnyOtherStatusIsPassedToTheStatusAwareCall()

	/**
	 * Leaf answers that do not carry tickets.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function unusableTicketAnswers(): array {
		return [
			'not an array' => ['tickets'],
			'no status' => [['tickets' => []]],
			'a non-200 status' => [['status' => 404, 'tickets' => []]],
			'a string status' => [['status' => '200', 'tickets' => []]],
			'no tickets key' => [['status' => 200]],
			'tickets not an array' => [['status' => 200, 'tickets' => 'none']],
		];
	}//end unusableTicketAnswers()

	/**
	 * @dataProvider unusableTicketAnswers
	 */
	public function testAnAnswerWithoutUsableTicketsIsNull(mixed $answer): void {
		$gateway = $this->gateway([PipelinqGateway::TICKETS => $this->ticketLeaf($answer)]);

		$this->assertNull($gateway->openTicketsByMasterEntity(refs: ['m1']));
		$this->assertNull($gateway->ticketsByMasterEntity(refs: ['m1'], status: 'all'));
	}//end testAnAnswerWithoutUsableTicketsIsNull()

	public function testAnAbsentTicketLeafIsNull(): void {
		$gateway = $this->gateway();

		$this->assertNull($gateway->openTicketsByMasterEntity(refs: ['m1']));
		$this->assertNull($gateway->ticketsByMasterEntity(refs: ['m1'], status: 'all'));
	}//end testAnAbsentTicketLeafIsNull()
}//end class
