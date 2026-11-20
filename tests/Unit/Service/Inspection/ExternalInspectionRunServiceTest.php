<?php

/**
 * External run tests: an external inspector's run is a portal task, completed only by its own party.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Inspection
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
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Kernel\Contracts\InspectionRunServiceInterface;
use OCA\Dossiq\Service\Inspection\ExternalInspectionRunService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Service\Portal\PortalCompletion;
use OCA\OpenRegister\Service\Portal\PortalSubject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see ExternalInspectionRunService}.
 *
 * @covers \OCA\Dossiq\Service\Inspection\ExternalInspectionRunService
 * @uses   \OCA\Dossiq\Service\Inspection\InspectionAnswers
 * @uses   \OCA\Dossiq\Service\ChecklistService
 * @uses   \OCA\Dossiq\Service\Support\ChecklistPayloadReader
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
final class ExternalInspectionRunServiceTest extends TestCase {

	/**
	 * Assigning creates one open external inspection task for the inspector's party.
	 *
	 * @return void
	 */
	public function testAssigningCreatesAnOpenExternalInspectionTask(): void {
		$tasks = new class {
			/**
			 * Create calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $created = [];

			/**
			 * Record a create.
			 *
			 * @param array<string, mixed> $data  The task fields.
			 * @param string|null          $actor The actor.
			 *
			 * @return object The task.
			 */
			public function create(array $data, ?string $actor): object {
				$this->created[] = ['data' => $data, 'actor' => $actor];
				return ExternalInspectionRunServiceTest::task(row: ['uuid' => 'task-9'] + $data);
			}
		};
		$runs = $this->createMock(InspectionRunServiceInterface::class);
		$runs->expects($this->once())->method('templateFor')->with('t-1')->willReturn(self::template());
		$runs->method('asRun')->willReturnCallback(static fn (array $task): array => ['id' => $task['uuid'], 'state' => $task['state']]);

		$service = new ExternalInspectionRunService($this->settings(class: $tasks), $runs, $this->createMock(LoggerInterface::class));
		$run = $service->assign(caseId: 'case-1', templateId: 't-1', inspectorRef: ' sub-42 ', payload: ['inspection' => 'visit-3', 'dueAt' => '2026-12-01', 'assignee' => 'someone-else'], actor: 'behandelaar');

		$data = $tasks->created[0]['data'];
		$this->assertSame('behandelaar', $tasks->created[0]['actor']);
		$this->assertSame('inspection', $data['kind']);
		$this->assertSame('external', $data['performerType']);
		$this->assertSame('party:sub-42', $data['assignee'], 'the assignee is the reference the caller named, never a body field');
		$this->assertSame('active', $data['state']);
		$this->assertSame('case-1', $data['objectUuid']);
		$this->assertSame('t-1', $data['templateId']);
		$this->assertSame(2, $data['templateVersion']);
		$this->assertSame('Fundering', $data['templateSnapshot']['name']);
		$this->assertSame('2026-12-01', $data['dueAt']);
		$this->assertSame([7, 11], [$data['registerId'], $data['schemaId']]);
		$this->assertSame(
			['completeVia' => ['app' => 'dossiq', 'action' => 'completeChecklistTask'], 'partyReference' => 'party:sub-42', 'inspection' => 'visit-3'],
			$data['metadata']
		);
		$this->assertSame(['id' => 'task-9', 'state' => 'active'], $run);
	}//end testAssigningCreatesAnOpenExternalInspectionTask()

	/**
	 * A missing or malformed reference is refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testAssigningWithoutAUsableReferenceIsRefused(): void {
		$runs = $this->createMock(InspectionRunServiceInterface::class);
		$runs->expects($this->never())->method('templateFor');
		$service = new ExternalInspectionRunService($this->settings(class: null), $runs, $this->createMock(LoggerInterface::class));

		foreach (['', '   ', 'two words'] as $ref) {
			try {
				$service->assign(caseId: 'case-1', templateId: 't-1', inspectorRef: $ref, payload: [], actor: 'u');
				$this->fail('reference "' . $ref . '" must be refused');
			} catch (RuntimeException $e) {
				$this->assertSame('inspectorRef is required', $e->getMessage());
			}
		}
	}//end testAssigningWithoutAUsableReferenceIsRefused()

	/**
	 * Without the task layer an assignment is refused.
	 *
	 * @return void
	 */
	public function testAssigningWithoutTheTaskLayerIsRefused(): void {
		$runs = $this->createMock(InspectionRunServiceInterface::class);
		$runs->method('templateFor')->willReturn(self::template());
		$service = new ExternalInspectionRunService($this->settings(class: null), $runs, $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The OpenRegister task layer is not available');
		$service->assign(caseId: 'case-1', templateId: 't-1', inspectorRef: 'sub-42', payload: [], actor: 'u');
	}//end testAssigningWithoutTheTaskLayerIsRefused()

	/**
	 * The party completes its own task through dossiq: answers checked, outcome computed, viaApp dossiq.
	 *
	 * @return void
	 */
	public function testThePartyCompletesItsOwnTask(): void {
		$portal = self::portal(task: self::openTask());
		$service = $this->service(portal: $portal);

		$result = $service->complete(
			claims: ['sub' => 'sub-42', 'audience' => 'inspector', 'trust' => 'low', 'organisation' => 'org', 'jti' => 's1'],
			taskId: 'task-9',
			payload: ['responses' => [['itemId' => 'q1', 'value' => 'ja', 'photos' => ['999']], ['itemId' => 'q2', 'value' => 'nee']], 'remarks' => ' kleine afwijking ']
		);

		$this->assertSame(200, $result['status']);
		$this->assertSame('task-9', $result['body']['id']);
		$this->assertSame('sub-42', $portal->shown[0]->subjectRef);
		$this->assertSame('inspector', $portal->shown[0]->claims->audience);
		$call = $portal->completed[0];
		$this->assertSame('dossiq', $call['viaApp']);
		$this->assertSame('task-9', $call['uuid']);
		$this->assertInstanceOf(PortalCompletion::class, $call['completion']);
		$this->assertSame('partly_conform', $call['completion']->outcome);
		$this->assertSame('kleine afwijking', $call['completion']->comment);
		$this->assertSame([], $call['completion']->files);
		$this->assertSame(['q1', 'q2'], array_column($call['completion']->answers, 'itemId'));
		$this->assertArrayNotHasKey('photos', $call['completion']->answers[0], 'a file id a portal party names is not evidence');
	}//end testThePartyCompletesItsOwnTask()

	/**
	 * A task stored for another party reads as absent and is not completed.
	 *
	 * @return void
	 */
	public function testAnotherPartysTaskReadsAsAbsent(): void {
		$portal = self::portal(task: ['assignee' => 'party:sub-7'] + self::openTask());
		$result = $this->service(portal: $portal)->complete(claims: ['sub' => 'sub-42'], taskId: 'task-9', payload: ['responses' => [['itemId' => 'q1', 'value' => 'ja']]]);

		$this->assertSame(['status' => 404, 'body' => ['error' => 'not_found']], $result);
		$this->assertSame([], $portal->completed);
	}//end testAnotherPartysTaskReadsAsAbsent()

	/**
	 * A task of another kind, or not an external one, is not a checklist to complete here.
	 *
	 * @return void
	 */
	public function testATaskThatIsNotAnExternalInspectionReadsAsAbsent(): void {
		foreach ([['kind' => 'question'], ['performerType' => 'user']] as $override) {
			$portal = self::portal(task: $override + self::openTask());
			$result = $this->service(portal: $portal)->complete(claims: ['sub' => 'sub-42'], taskId: 'task-9', payload: ['responses' => [['itemId' => 'q1', 'value' => 'ja']]]);

			$this->assertSame(404, $result['status']);
			$this->assertSame([], $portal->completed);
		}
	}//end testATaskThatIsNotAnExternalInspectionReadsAsAbsent()

	/**
	 * A task the seam will not show the subject reads as absent.
	 *
	 * @return void
	 */
	public function testATaskTheSeamRefusesReadsAsAbsent(): void {
		$portal = self::portal(task: null);
		$result = $this->service(portal: $portal)->complete(claims: ['sub' => 'sub-42'], taskId: 'task-9', payload: []);

		$this->assertSame(404, $result['status']);
		$this->assertSame([], $portal->completed);
	}//end testATaskTheSeamRefusesReadsAsAbsent()

	/**
	 * Answers that break the frozen template's gates are refused with nothing written.
	 *
	 * @return void
	 */
	public function testAnswersThatBreakTheTemplateAreRefused(): void {
		$portal = self::portal(task: self::openTask());
		$result = $this->service(portal: $portal)->complete(claims: ['sub' => 'sub-42'], taskId: 'task-9', payload: ['responses' => [['itemId' => 'q1', 'value' => 'nee', 'photos' => ['5']]]]);

		$this->assertSame(422, $result['status']);
		$this->assertStringContainsString('photo', $result['body']['message']);
		$this->assertSame([], $portal->completed);
	}//end testAnswersThatBreakTheTemplateAreRefused()

	/**
	 * No subject, no task id, or no seam: refused before the seam is asked.
	 *
	 * @return void
	 */
	public function testIncompleteRequestsAreRefusedFirst(): void {
		$portal = self::portal(task: self::openTask());

		$this->assertSame(401, $this->service(portal: $portal)->complete(claims: ['sub' => ' '], taskId: 'task-9', payload: [])['status']);
		$this->assertSame(400, $this->service(portal: $portal)->complete(claims: ['sub' => 'sub-42'], taskId: ' ', payload: [])['status']);
		$this->assertSame(503, $this->service(portal: null)->complete(claims: ['sub' => 'sub-42'], taskId: 'task-9', payload: [])['status']);
		$this->assertSame([], $portal->shown);
	}//end testIncompleteRequestsAreRefusedFirst()

	/**
	 * A terminal task is a 409; any other refusal a logged 503.
	 *
	 * @return void
	 */
	public function testARefusedCompletionIsAnswered(): void {
		$portal = self::portal(task: self::openTask(), throws: new TaskConflictException('terminal'));
		$result = $this->service(portal: $portal)->complete(claims: ['sub' => 'sub-42'], taskId: 'task-9', payload: ['responses' => [['itemId' => 'q1', 'value' => 'ja']]]);
		$this->assertSame(409, $result['status']);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$portal = self::portal(task: self::openTask(), throws: new RuntimeException('database gone'));
		$result = (new ExternalInspectionRunService($this->settings(class: $portal), $this->runs(), $logger))
			->complete(claims: ['sub' => 'sub-42'], taskId: 'task-9', payload: ['responses' => [['itemId' => 'q1', 'value' => 'ja']]]);
		$this->assertSame(['status' => 503, 'body' => ['error' => 'unavailable']], $result);
	}//end testARefusedCompletionIsAnswered()

	/**
	 * A task-shaped object.
	 *
	 * @param array<string, mixed> $row The serialised task.
	 *
	 * @return object The task.
	 */
	public static function task(array $row): object {
		return new class($row) {
			/**
			 * Hold the row.
			 *
			 * @param array<string, mixed> $row The serialised task.
			 */
			public function __construct(private readonly array $row) {
			}

			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}
		};
	}//end task()

	/**
	 * The service over a portal seam, or none.
	 *
	 * @param object|null $portal The seam.
	 *
	 * @return ExternalInspectionRunService The service.
	 */
	private function service(?object $portal): ExternalInspectionRunService {
		return new ExternalInspectionRunService($this->settings(class: $portal), $this->runs(), $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A run service answering the run shape.
	 *
	 * @return InspectionRunServiceInterface The stub.
	 */
	private function runs(): InspectionRunServiceInterface {
		$runs = $this->createMock(InspectionRunServiceInterface::class);
		$runs->method('asRun')->willReturnCallback(static fn (array $task): array => ['id' => $task['uuid'], 'result' => ($task['outcome'] ?? '')]);

		return $runs;
	}//end runs()

	/**
	 * Settings answering one OpenRegister class and the register config.
	 *
	 * @param object|null $class The class getOpenRegisterClass answers.
	 *
	 * @return SettingsService The stub.
	 */
	private function settings(?object $class): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn($class);
		$settings->method('getConfigValue')->willReturnMap([['register', '', '7'], ['case_schema', '', '11']]);

		return $settings;
	}//end settings()

	/**
	 * An open external inspection task for party sub-42.
	 *
	 * @return array<string, mixed> The serialised task.
	 */
	private static function openTask(): array {
		return [
			'uuid' => 'task-9',
			'kind' => 'inspection',
			'performerType' => 'external',
			'assignee' => 'party:sub-42',
			'state' => 'active',
			'templateSnapshot' => self::template(),
		];
	}//end openTask()

	/**
	 * A portal seam recording show and complete.
	 *
	 * @param array<string, mixed>|null $task   The task show answers; null throws.
	 * @param \Throwable|null           $throws What complete throws, if anything.
	 *
	 * @return object The seam.
	 */
	private static function portal(?array $task, ?\Throwable $throws = null): object {
		return new class($task, $throws) {
			/**
			 * The subjects shown a task.
			 *
			 * @var array<int, PortalSubject>
			 */
			public array $shown = [];

			/**
			 * The completions.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $completed = [];

			/**
			 * Hold the task.
			 *
			 * @param array<string, mixed>|null $task   The task.
			 * @param \Throwable|null           $throws What complete throws.
			 */
			public function __construct(private readonly ?array $task, private readonly ?\Throwable $throws) {
			}

			/**
			 * Show the task to a subject.
			 *
			 * @param PortalSubject $subject The subject.
			 * @param string        $uuid    The task.
			 *
			 * @return object The task.
			 */
			public function show(PortalSubject $subject, string $uuid): object {
				$this->shown[] = $subject;
				if ($this->task === null) {
					throw new RuntimeException('No such portal task.');
				}

				return ExternalInspectionRunServiceTest::task(row: $this->task + ['requested' => $uuid]);
			}

			/**
			 * Complete the task.
			 *
			 * @param PortalSubject    $subject    The subject.
			 * @param string           $uuid       The task.
			 * @param PortalCompletion $completion The completion.
			 * @param string           $viaApp     The completing app.
			 *
			 * @return object The completed task.
			 */
			public function complete(PortalSubject $subject, string $uuid, PortalCompletion $completion, string $viaApp = ''): object {
				if ($this->throws !== null) {
					throw $this->throws;
				}

				$this->completed[] = compact('subject', 'uuid', 'completion', 'viaApp');
				return ExternalInspectionRunServiceTest::task(row: ['uuid' => $uuid, 'outcome' => $completion->outcome, 'state' => 'completed']);
			}
		};
	}//end portal()

	/**
	 * A template with a required yes/no item (photo on nee) and an optional one.
	 *
	 * @return array<string, mixed> The template.
	 */
	private static function template(): array {
		return [
			'@self' => ['id' => 't-1'],
			'name' => 'Fundering',
			'version' => 2,
			'sections' => [
				[
					'items' => [
						['id' => 'q1', 'label' => 'Wapening', 'responseType' => 'yes_no_na', 'required' => true, 'photoRequired' => 'if_no'],
						['id' => 'q2', 'label' => 'Maatvoering', 'responseType' => 'yes_no_na'],
					],
				],
			],
		];
	}//end template()
}//end class
