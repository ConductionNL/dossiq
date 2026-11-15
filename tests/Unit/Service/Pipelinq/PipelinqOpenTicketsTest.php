<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A party's open pipelinq tickets, read by master entity through the gateway.
 *
 * @spec openspec/specs/pipelinq-consumption/spec.md
 */
class PipelinqOpenTicketsTest extends TestCase {

	/**
	 * A gateway whose container holds the given ticket leaf, or nothing.
	 *
	 * @param object|null $leaf The ticket leaf, or null for an absent pipelinq.
	 *
	 * @return PipelinqGateway The gateway under test.
	 */
	private function gateway(?object $leaf): PipelinqGateway {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($leaf): object {
				if ($leaf === null || $id !== PipelinqGateway::TICKETS) {
					throw new RuntimeException("nothing answers to {$id}");
				}

				return $leaf;
			}
		);

		return new PipelinqGateway(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end gateway()

	/**
	 * A ticket leaf that answers with the given result and records what it was asked.
	 *
	 * @param array<string, mixed> $result The leaf's answer.
	 *
	 * @return object The leaf.
	 */
	private function leaf(array $result): object {
		return new class ($result) {
			/**
			 * The references the leaf was asked for.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * The status filter the leaf was asked for.
			 *
			 * @var string
			 */
			public string $status = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $result The answer.
			 */
			public function __construct(private readonly array $result) {
			}

			/**
			 * Answer the open tickets.
			 *
			 * @param array<int, string> $refs The master entities.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function openByMasterEntity(array $refs): array {
				$this->asked = $refs;
				return $this->result;
			}

			/**
			 * Answer the tickets by status.
			 *
			 * @param array<int, string> $refs The master entities.
			 * @param string $status The status filter.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function byMasterEntity(array $refs, string $status='open'): array {
				$this->asked = $refs;
				$this->status = $status;
				return $this->result;
			}
		};
	}//end leaf()

	/**
	 * An absent pipelinq answers null, not an empty map.
	 *
	 * @return void
	 */
	public function testAnAbsentPipelinqAnswersNull(): void {
		$this->assertNull(actual: $this->gateway(leaf: null)->openTicketsByMasterEntity(refs: ['m1']));
	}//end testAnAbsentPipelinqAnswersNull()

	/**
	 * The tickets pipelinq answers come back per master entity.
	 *
	 * @return void
	 */
	public function testTheTicketsComeBackPerMasterEntity(): void {
		$leaf = $this->leaf(result: ['status' => 200, 'tickets' => ['m1' => [['id' => 't1']], 'm2' => []]]);

		$tickets = $this->gateway(leaf: $leaf)->openTicketsByMasterEntity(refs: ['m1', 'm2']);

		$this->assertSame(expected: ['m1' => [['id' => 't1']], 'm2' => []], actual: $tickets);
		$this->assertSame(expected: ['m1', 'm2'], actual: $leaf->asked);
	}//end testTheTicketsComeBackPerMasterEntity()

	/**
	 * A pipelinq whose ticket surface is not configured is as good as absent.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredTicketSurfaceAnswersNull(): void {
		$leaf = $this->leaf(result: ['status' => 503, 'error' => 'The ticket surface is not configured.']);

		$this->assertNull(actual: $this->gateway(leaf: $leaf)->openTicketsByMasterEntity(refs: ['m1']));
	}//end testAnUnconfiguredTicketSurfaceAnswersNull()

	/**
	 * `all` asks pipelinq's byMasterEntity with the filter; `open` keeps the
	 * open read, so an older pipelinq still serves it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-tickets-all-with-reference/specs/pipelinq-consumption/spec.md#requirement-a-partys-tickets-card-lists-its-handled-tickets-too
	 */
	public function testAllAsksTheStatusFilter(): void {
		$leaf = $this->leaf(result: ['status' => 200, 'tickets' => ['m1' => [['id' => 't4']]]]);

		$this->assertSame(expected: ['m1' => [['id' => 't4']]], actual: $this->gateway(leaf: $leaf)->ticketsByMasterEntity(refs: ['m1'], status: 'all'));
		$this->assertSame(expected: 'all', actual: $leaf->status);

		$this->assertSame(expected: ['m1' => [['id' => 't4']]], actual: $this->gateway(leaf: $leaf)->ticketsByMasterEntity(refs: ['m1'], status: 'open'));
		$this->assertSame(expected: 'all', actual: $leaf->status, message: 'open reads openByMasterEntity');
	}//end testAllAsksTheStatusFilter()
}//end class
