<?php

/**
 * Tests for the generic question-to-the-resident portal task (decision 169, 182).
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

use OCA\Dossiq\Portal\ResidentQuestionTask;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
 */
class ResidentQuestionTaskTest extends TestCase {

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
	 * @return ResidentQuestionTask
	 */
	private function unit(?object $tasks): ResidentQuestionTask {
		$container = $this->createMock(ContainerInterface::class);
		if ($tasks === null) {
			$container->method('get')->willThrowException(new RuntimeException('no such service'));
		} else {
			$container->method('get')->with(ResidentQuestionTask::TASK_SERVICE)->willReturn($tasks);
		}

		return new ResidentQuestionTask(container: $container, logger: new NullLogger());
	}//end unit()

	/**
	 * A question as a caller hands it over.
	 *
	 * @return array<string, mixed>
	 */
	private function question(): array {
		return [
			'id' => 'avr-1',
			'case' => 'case-1',
			'portalSubject' => 'person:bsn-hash-1',
			'title' => 'Vul uw aanvraag aan',
			'items' => ['Bankafschrift', 'Huurcontract'],
			'due' => '2026-10-24',
			'source' => 'dossiq.aanvullingsverzoek',
		];
	}//end question()

	/**
	 * The task goes to the resident's party reference, on the case, due on
	 * the hersteltermijn, and names every missing item.
	 *
	 * @return void
	 */
	public function testTheTaskIsTheResidentsOnTheCaseDueOnTheHersteltermijn(): void {
		$tasks = $this->tasks();
		$uuid = $this->unit(tasks: $tasks)->raise(question: $this->question(), actor: 'handler1');

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
		$this->assertSame('avr-1', $data['metadata']['question']);
		$this->assertSame('dossiq.aanvullingsverzoek', $data['metadata']['source']);
		$this->assertSame('Vul uw aanvraag aan', $data['title']);
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
		foreach (['portalSubject', 'case', 'id', 'title'] as $missing) {
			$tasks = $this->tasks();
			$question = $this->question();
			$question[$missing] = '';
			$this->assertNull($this->unit(tasks: $tasks)->raise(question: $question, actor: 'h'), $missing);
			$this->assertSame([], $tasks->imported, $missing);
		}

		$this->assertNull($this->unit(tasks: $this->tasks(refuse: true))->raise(question: $this->question(), actor: 'h'));
		$this->assertNull($this->unit(tasks: null)->raise(question: $this->question(), actor: 'h'));
	}//end testNoTaskIsRaisedWithoutAResidentAndAFailureNeverThrows()

	/**
	 * A settled question closes its task with the reason and the source; no
	 * task closes nothing, and a refusing OpenRegister never throws.
	 *
	 * @return void
	 */
	public function testASettledQuestionClosesItsTask(): void {
		$tasks = $this->tasks();
		$unit = $this->unit(tasks: $tasks);

		$this->assertTrue($unit->close(taskUuid: 'task-1', reason: 'The aanvullingsverzoek is answered in dossiq.', source: 'dossiq.aanvullingsverzoek'));
		$this->assertSame(['uuid' => 'task-1', 'reason' => 'The aanvullingsverzoek is answered in dossiq.', 'source' => 'dossiq.aanvullingsverzoek'], $tasks->terminated[0]);

		$this->assertFalse($unit->close(taskUuid: '', reason: 'x', source: 's'));
		$this->assertCount(1, $tasks->terminated);

		$this->assertFalse($this->unit(tasks: $this->tasks(refuse: true))->close(taskUuid: 'task-1', reason: 'x', source: 's'));
	}//end testASettledQuestionClosesItsTask()

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
