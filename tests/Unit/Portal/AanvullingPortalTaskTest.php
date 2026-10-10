<?php

/**
 * Tests for the portal task an aanvullingsverzoek raises (decision 169).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\AanvullingPortalTask;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
 */
class AanvullingPortalTaskTest extends TestCase {

	/**
	 * A stand-in for OpenRegister's task service, recording what it was asked.
	 *
	 * @param bool $refuse Whether every call throws.
	 *
	 * @return object
	 */
	private function tasks(bool $refuse = false): object {
		return new class($refuse) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $imported = [];

			/**
			 * @var array<int, array<string, string>>
			 */
			public array $terminated = [];

			/**
			 * @param bool $refuse Whether every call throws.
			 */
			public function __construct(private bool $refuse) {
			}

			/**
			 * @param array<string, mixed> $data  The task.
			 * @param string|null          $actor Who.
			 *
			 * @return object
			 */
			public function import(array $data, ?string $actor): object {
				if ($this->refuse === true) {
					throw new RuntimeException('refused');
				}

				$this->imported[] = ['data' => $data, 'actor' => $actor];
				return new class {
					/**
					 * @return string
					 */
					public function getUuid(): string {
						return 'task-1';
					}
				};
			}

			/**
			 * @param string $uuid   The task.
			 * @param string $reason Why.
			 * @param string $source Who.
			 *
			 * @return object
			 */
			public function terminateAsMoot(string $uuid, string $reason, string $source): object {
				if ($this->refuse === true) {
					throw new RuntimeException('refused');
				}

				$this->terminated[] = ['uuid' => $uuid, 'reason' => $reason, 'source' => $source];
				return new \stdClass();
			}
		};
	}//end tasks()

	/**
	 * The unit under test over a container that answers with $tasks.
	 *
	 * @param object|null $tasks The task service, or null when OpenRegister has none.
	 *
	 * @return AanvullingPortalTask
	 */
	private function unit(?object $tasks): AanvullingPortalTask {
		$container = $this->createMock(ContainerInterface::class);
		if ($tasks === null) {
			$container->method('get')->willThrowException(new RuntimeException('no such service'));
		} else {
			$container->method('get')->with(AanvullingPortalTask::TASK_SERVICE)->willReturn($tasks);
		}

		return new AanvullingPortalTask(container: $container, logger: new NullLogger());
	}//end unit()

	/**
	 * A request as AanvullingsverzoekService writes it.
	 *
	 * @return array<string, mixed>
	 */
	private function request(): array {
		return [
			'id' => 'avr-1',
			'case' => 'case-1',
			'portalSubject' => 'person:bsn-hash-1',
			'summary' => 'Bankafschrift',
			'missingItems' => [['item' => 'Bankafschrift', 'received' => false], ['item' => 'Huurcontract', 'received' => false]],
			'hersteltermijn' => '2026-10-24',
			'state' => 'open',
		];
	}//end request()

	/**
	 * The task goes to the resident's party reference, on the case, due on
	 * the hersteltermijn, and names every missing item.
	 *
	 * @return void
	 */
	public function testTheTaskIsTheResidentsOnTheCaseDueOnTheHersteltermijn(): void {
		$tasks = $this->tasks();
		$uuid = $this->unit(tasks: $tasks)->raise(request: $this->request(), actor: 'handler1');

		$this->assertSame('task-1', $uuid);
		$this->assertCount(1, $tasks->imported);
		$data = $tasks->imported[0]['data'];
		$this->assertSame('handler1', $tasks->imported[0]['actor']);
		$this->assertSame('party:person:bsn-hash-1', $data['assignee']);
		$this->assertSame('external', $data['performerType']);
		$this->assertSame('active', $data['state']);
		$this->assertSame('case-1', $data['objectUuid']);
		$this->assertSame('2026-10-24T23:59:59+02:00', $data['dueAt']);
		$this->assertStringContainsString('Bankafschrift', $data['description']);
		$this->assertStringContainsString('Huurcontract', $data['description']);
		$this->assertStringNotContainsString('—', $data['title'] . $data['description']);
		$this->assertSame('avr-1', $data['metadata']['aanvullingsverzoek']);
		$this->assertSame(AanvullingPortalTask::SOURCE, $data['metadata']['source']);
		$this->assertSame('party:person:bsn-hash-1', $data['metadata']['partyReference']);
	}//end testTheTaskIsTheResidentsOnTheCaseDueOnTheHersteltermijn()

	/**
	 * Without a portal subject, a case or an id there is nobody to give the
	 * task to, so none is raised; a refusing or absent OpenRegister returns
	 * null and never throws, because the letter has already gone out.
	 *
	 * @return void
	 */
	public function testNoTaskIsRaisedWithoutAResidentAndAFailureNeverThrows(): void {
		foreach (['portalSubject', 'case', 'id'] as $missing) {
			$tasks = $this->tasks();
			$request = $this->request();
			$request[$missing] = '';
			$this->assertNull($this->unit(tasks: $tasks)->raise(request: $request, actor: 'h'), $missing);
			$this->assertSame([], $tasks->imported, $missing);
		}

		$this->assertNull($this->unit(tasks: $this->tasks(refuse: true))->raise(request: $this->request(), actor: 'h'));
		$this->assertNull($this->unit(tasks: null)->raise(request: $this->request(), actor: 'h'));
	}//end testNoTaskIsRaisedWithoutAResidentAndAFailureNeverThrows()

	/**
	 * A request that leaves `open` closes its task, saying why; one without a
	 * task, or still open, closes nothing.
	 *
	 * @return void
	 */
	public function testARequestThatLeavesOpenClosesItsTask(): void {
		$tasks = $this->tasks();
		$unit = $this->unit(tasks: $tasks);

		$this->assertTrue($unit->close(request: ['portalTask' => 'task-1', 'state' => 'answered']));
		$this->assertSame('task-1', $tasks->terminated[0]['uuid']);
		$this->assertSame(AanvullingPortalTask::SOURCE, $tasks->terminated[0]['source']);
		$this->assertStringContainsString('answered', $tasks->terminated[0]['reason']);

		$this->assertFalse($unit->close(request: ['portalTask' => 'task-1', 'state' => 'open']));
		$this->assertFalse($unit->close(request: ['portalTask' => '', 'state' => 'expired']));
		$this->assertCount(1, $tasks->terminated);

		$this->assertFalse($this->unit(tasks: $this->tasks(refuse: true))->close(request: ['portalTask' => 'task-1', 'state' => 'withdrawn']));
	}//end testARequestThatLeavesOpenClosesItsTask()

	/**
	 * The request schema takes the task's uuid, so remembering it cannot be
	 * refused by the register the request lives in.
	 *
	 * @return void
	 */
	public function testTheRequestSchemaTakesThePortalTask(): void {
		$register = new RealSchemaValidator();
		$this->assertArrayHasKey('portalTask', $register->schemas['aanvullingsverzoek']['properties']);
		$this->assertSame(
			[],
			$register->errors(slug: 'aanvullingsverzoek', payload: ['portalTask' => '0b7c6f1e-2d4a-4c1b-9f0e-1a2b3c4d5e6f'], creating: false)
		);
	}//end testTheRequestSchemaTakesThePortalTask()
}//end class
