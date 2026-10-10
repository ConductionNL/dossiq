<?php

/**
 * Run tests: a submitted run is one completed task; a case's runs come back.
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionRunService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for {@see InspectionRunService}.
 *
 * @covers \OCA\Dossiq\Service\Inspection\InspectionRunService
 * @uses   \OCA\Dossiq\Service\Inspection\InspectionAnswers
 * @uses   \OCA\Dossiq\Service\ChecklistService
 * @uses   \OCA\Dossiq\Service\Support\ChecklistPayloadReader
 */
final class InspectionRunServiceTest extends TestCase {

	/**
	 * The stand-in task layer.
	 *
	 * @var object
	 */
	private object $tasks;

	/**
	 * Build the task layer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tasks = self::taskLayer();
	}//end setUp()

	/**
	 * A run becomes one task: created active with the frozen template, then completed.
	 *
	 * @return void
	 */
	public function testASubmittedRunIsOneCompletedTask(): void {
		$service = new InspectionRunService($this->settings(templates: ['t-1' => self::template()]), $this->createMock(EngineInboxQuery::class));

		$run = $service->submit(
			caseId: 'case-1',
			templateId: 't-1',
			payload: [
				'items' => [['itemId' => 'q1', 'result' => 'fail', 'photos' => ['77']], ['itemId' => 'q2', 'result' => 'pass']],
				'remarks' => 'scheur in fundering',
				'location' => ['lat' => 52.1, 'lon' => 5.3, 'accuracy' => 8, 'source' => 'gps'],
				'capturedOffline' => true,
				'syncState' => 'queued',
			],
			actor: 'inspecteur-a'
		);

		$created = $this->tasks->created[0];
		$this->assertSame('inspection', $created['data']['kind']);
		$this->assertSame('case-1', $created['data']['objectUuid']);
		$this->assertSame('inspecteur-a', $created['data']['assignee']);
		$this->assertSame('active', $created['data']['state']);
		$this->assertSame('t-1', $created['data']['templateId']);
		$this->assertSame(2, $created['data']['templateVersion']);
		$this->assertSame('Fundering', $created['data']['templateSnapshot']['name']);
		$this->assertSame([7, 11], [$created['data']['registerId'], $created['data']['schemaId']]);
		$this->assertSame(['location', 'capturedOffline'], array_keys($created['data']['metadata']), 'syncState is a device state and is not stored');

		$completed = $this->tasks->completed[0];
		$this->assertSame('partly_conform', $completed['outcome']);
		$this->assertSame('scheur in fundering', $completed['comment']);
		$this->assertSame(['77'], $completed['evidence']);
		$this->assertSame('nee', $completed['responses'][0]['value']);

		$this->assertSame('partly_conform', $run['result']);
		$this->assertSame(1, $run['failedItems']);
		$this->assertSame('fail', $run['items'][0]['result']);
		$this->assertSame('inspecteur-a', $run['inspector']);
	}//end testASubmittedRunIsOneCompletedTask()

	/**
	 * A run that breaks the photo gate writes no task.
	 *
	 * @return void
	 */
	public function testARefusedRunWritesNothing(): void {
		$service = new InspectionRunService($this->settings(templates: ['t-1' => self::template()]), $this->createMock(EngineInboxQuery::class));

		try {
			$service->submit(caseId: 'case-1', templateId: 't-1', payload: ['items' => [['itemId' => 'q1', 'result' => 'fail']]], actor: 'inspecteur-a');
			$this->fail('a nee on q1 without a photo must be refused');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('photo', $e->getMessage());
		}

		$this->assertSame([], $this->tasks->created);
	}//end testARefusedRunWritesNothing()

	/**
	 * An older checklist id finds the template folded from it.
	 *
	 * @return void
	 */
	public function testAnOlderChecklistIdFindsItsFoldedTemplate(): void {
		$service = new InspectionRunService(
			$this->settings(templates: [], folded: ['inspectionChecklist/old-1' => self::template()]),
			$this->createMock(EngineInboxQuery::class)
		);

		$service->submit(caseId: 'case-1', templateId: 'old-1', payload: ['items' => [['itemId' => 'q1', 'result' => 'pass']]], actor: 'inspecteur-a');

		$this->assertSame('t-1', $this->tasks->created[0]['data']['templateId']);
	}//end testAnOlderChecklistIdFindsItsFoldedTemplate()

	/**
	 * An unknown template is refused by name.
	 *
	 * @return void
	 */
	public function testAnUnknownTemplateIsRefused(): void {
		$service = new InspectionRunService($this->settings(templates: []), $this->createMock(EngineInboxQuery::class));

		$this->expectExceptionMessage('Unknown checklist template: nope');
		$service->submit(caseId: 'case-1', templateId: 'nope', payload: [], actor: 'inspecteur-a');
	}//end testAnUnknownTemplateIsRefused()

	/**
	 * A case's runs are its inspection tasks, oldest first.
	 *
	 * @return void
	 */
	public function testACasesRunsAreItsInspectionTasks(): void {
		$inbox = $this->createMock(EngineInboxQuery::class);
		$inbox->method('scope')->willReturn('all');
		$inbox->expects($this->once())->method('rows')
			->with($this->callback(static fn (array $c): bool => $c['objectUuid'] === 'case-1' && $c['kind'] === 'inspection' && $c['scope'] === 'all' && $c['uid'] === 'u'))
			->willReturn(
				[
					['uuid' => 'b', 'objectUuid' => 'case-1', 'templateId' => 't-1', 'completedAt' => '2026-10-02T10:00:00+00:00', 'outcome' => 'conform', 'responses' => []],
					['uuid' => 'a', 'objectUuid' => 'case-1', 'templateId' => 't-1', 'completedAt' => '2026-10-01T10:00:00+00:00', 'outcome' => 'non_conform', 'responses' => [['itemId' => 'q1', 'value' => 'nee']], 'metadata' => ['location' => ['lat' => 1]]],
				]
			);
		$service = new InspectionRunService($this->settings(templates: []), $inbox);

		$runs = $service->runsForCase(caseId: 'case-1', actor: 'u');

		$this->assertSame(['a', 'b'], array_column($runs, 'id'));
		$this->assertSame(1, $runs[0]['failedItems']);
		$this->assertSame(['lat' => 1], $runs[0]['location']);
		$this->assertSame([], $service->runsForCase(caseId: 'case-1', actor: ''), 'no identity is no read');
	}//end testACasesRunsAreItsInspectionTasks()

	/**
	 * Settings over in-memory templates and the task layer.
	 *
	 * @param array<string, array<string, mixed>> $templates Templates by uuid.
	 * @param array<string, array<string, mixed>> $folded    Templates by legacyRef.
	 *
	 * @return SettingsService The stub.
	 */
	private function settings(array $templates, array $folded = []): SettingsService {
		$objects = new class($templates, $folded) {
			/**
			 * Hold the templates.
			 *
			 * @param array<string, array<string, mixed>> $templates By uuid.
			 * @param array<string, array<string, mixed>> $folded    By legacyRef.
			 */
			public function __construct(private readonly array $templates, private readonly array $folded) {
			}//end __construct()

			/**
			 * One template by uuid.
			 *
			 * @param string     $id       The uuid.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed>|null The template.
			 */
			public function find(string $id, int|string $register, int|string $schema): ?array {
				unset($register, $schema);

				return ($this->templates[$id] ?? null);
			}

			/**
			 * Templates by legacyRef.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The hits.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				unset($register, $schema);
				$hit = ($this->folded[$filters['legacyRef'] ?? ''] ?? null);

				return $hit === null ? [] : [$hit];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getOpenRegisterClass')->willReturn($this->tasks);
		$settings->method('getConfigValue')->willReturnMap([['register', '', '7'], ['case_schema', '', '11']]);

		return $settings;
	}//end settings()

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

	/**
	 * A task layer recording create and complete.
	 *
	 * @return object The layer.
	 */
	private static function taskLayer(): object {
		return new class {
			/**
			 * Create calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $created = [];

			/**
			 * Complete calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $completed = [];

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

				return self::task(row: ['uuid' => 'task-1'] + $data);
			}

			/**
			 * Record a complete.
			 *
			 * @param string                                $uuid       The task.
			 * @param string                                $outcome    The outcome.
			 * @param string|null                           $resultText The result text.
			 * @param string|null                           $comment    The comment.
			 * @param string|null                           $actor      The actor.
			 * @param array<int, array<string, mixed>>|null $responses  The answers.
			 * @param array<int, string>|null               $evidence   The file ids.
			 *
			 * @return object The task.
			 */
			public function complete(string $uuid, string $outcome, ?string $resultText, ?string $comment, ?string $actor, ?array $responses = null, ?array $evidence = null): object {
				$this->completed[] = compact('uuid', 'outcome', 'resultText', 'comment', 'actor', 'responses', 'evidence');
				$row = ['uuid' => $uuid, 'objectUuid' => $this->created[0]['data']['objectUuid'], 'templateId' => $this->created[0]['data']['templateId'], 'state' => 'completed', 'completedBy' => $actor, 'completedAt' => '2026-10-10T12:00:00+00:00', 'outcome' => $outcome, 'comment' => $comment, 'responses' => $responses, 'evidence' => $evidence];

				return self::task(row: $row);
			}

			/**
			 * A task-shaped object.
			 *
			 * @param array<string, mixed> $row The serialised task.
			 *
			 * @return object The task.
			 */
			private static function task(array $row): object {
				return new class($row) {
					/**
					 * Hold the row.
					 *
					 * @param array<string, mixed> $row The row.
					 */
					public function __construct(private readonly array $row) {
					}//end __construct()

					/**
					 * The uuid.
					 *
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return (string)$this->row['uuid'];
					}

					/**
					 * The row.
					 *
					 * @return array<string, mixed> The row.
					 */
					public function jsonSerialize(): array {
						return $this->row;
					}
				};
			}
		};
	}//end taskLayer()
}//end class
