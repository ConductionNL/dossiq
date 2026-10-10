<?php

/**
 * Repair-step tests for the inspection run carry-over.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CarryInspectionRunsOntoTasks;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see CarryInspectionRunsOntoTasks}.
 *
 * @covers \OCA\Dossiq\Repair\CarryInspectionRunsOntoTasks
 * @uses   \OCA\Dossiq\Service\Inspection\InspectionRunCarryOver
 * @uses   \OCA\Dossiq\Service\Inspection\InspectionAnswers
 * @uses   \OCA\Dossiq\Service\ChecklistService
 * @uses   \OCA\Dossiq\Service\Support\ChecklistPayloadReader
 */
final class CarryInspectionRunsOntoTasksTest extends TestCase {

	/**
	 * Every source is carried once; a second run carries nothing; a refusal is counted.
	 *
	 * @return void
	 */
	public function testEachRunIsCarriedOnceAndARefusalIsCounted(): void {
		$tasks = self::taskLayer(refuse: 'inspectionResult/c-bad');
		$store = self::store(
			[
				'inspectionChecklistTemplate' => [['@self' => ['id' => 't-1'], 'name' => 'Fundering', 'legacyRef' => 'inspectieChecklist/a-1', 'sections' => []]],
				'inspectieRapport' => [['@self' => ['id' => 'r-1'], 'case' => 'case-1', 'checklist' => 'a-1', 'inspector' => 'u1', 'result' => 'conform', 'items' => []]],
				'inspectionChecklistRun' => [['@self' => ['id' => 'b-1'], 'case' => 'case-1', 'template' => 't-1', 'status' => 'submitted', 'inspector' => 'u1', 'responses' => []]],
				'inspectionResult' => [['@self' => ['id' => 'c-bad'], 'case' => 'case-2', 'checklist' => 'x', 'completedBy' => 'u2', 'answers' => []]],
			]
		);

		$first = $this->createMock(IOutput::class);
		$first->expects($this->once())->method('warning')->with($this->stringContains('inspectionResult/c-bad'));
		$first->expects($this->once())->method('info')->with($this->stringContains('2 carried, 0 already carried, 1 failed'));
		$this->step(store: $store, tasks: $tasks)->run($first);

		$this->assertSame(['inspectieRapport/r-1', 'inspectionChecklistRun/b-1'], array_map(static fn (array $t): string => $t['metadata']['legacyRef'], $tasks->imported));
		$this->assertSame('t-1', $tasks->imported[0]['templateId'], 'the report found the template folded from its checklist');
		$this->assertSame([7, 11], [$tasks->imported[0]['registerId'], $tasks->imported[0]['schemaId']]);
		$this->assertSame('system:dossiq', $tasks->actors[0]);

		$second = $this->createMock(IOutput::class);
		$second->expects($this->once())->method('info')->with($this->stringContains('0 carried, 2 already carried, 1 failed'));
		$this->step(store: $store, tasks: $tasks)->run($second);
		$this->assertCount(2, $tasks->imported);
	}//end testEachRunIsCarriedOnceAndARefusalIsCounted()

	/**
	 * Without the task layer the step says so and does nothing.
	 *
	 * @return void
	 */
	public function testWithoutTheTaskLayerTheStepSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(new \stdClass());
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('Skipping'));

		(new CarryInspectionRunsOntoTasks($settings, $this->createMock(EngineInboxQuery::class), $this->createMock(LoggerInterface::class)))->run($output);
	}//end testWithoutTheTaskLayerTheStepSkips()

	/**
	 * A fresh step over the store and the task layer, with an inbox reading what was imported.
	 *
	 * @param object $store The object store.
	 * @param object $tasks The task layer.
	 *
	 * @return CarryInspectionRunsOntoTasks The step.
	 */
	private function step(object $store, object $tasks): CarryInspectionRunsOntoTasks {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getOpenRegisterClass')->willReturn($tasks);
		$settings->method('getConfigValue')->willReturnMap([['register', '', '7'], ['case_schema', '', '11']]);

		$inbox = $this->createMock(EngineInboxQuery::class);
		$inbox->method('scope')->willReturn('all');
		$inbox->method('rows')->willReturnCallback(
			static fn (array $criteria): array => array_values(
				array_filter($tasks->imported, static fn (array $t): bool => $t['objectUuid'] === $criteria['objectUuid'] && $t['kind'] === $criteria['kind'])
			)
		);

		return new CarryInspectionRunsOntoTasks($settings, $inbox, $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * A task layer recording imports, refusing one legacy reference.
	 *
	 * @param string $refuse The legacyRef to refuse.
	 *
	 * @return object The layer.
	 */
	private static function taskLayer(string $refuse): object {
		return new class($refuse) {
			/**
			 * Imported task fields.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $imported = [];

			/**
			 * The actors of each import.
			 *
			 * @var array<int, string|null>
			 */
			public array $actors = [];

			/**
			 * Hold the reference to refuse.
			 *
			 * @param string $refuse The legacyRef.
			 */
			public function __construct(private readonly string $refuse) {
			}//end __construct()

			/**
			 * Record an import, or refuse it.
			 *
			 * @param array<string, mixed> $data  The task fields.
			 * @param string|null          $actor The actor.
			 *
			 * @return object The task.
			 */
			public function import(array $data, ?string $actor): object {
				if ($data['metadata']['legacyRef'] === $this->refuse) {
					throw new \RuntimeException('refused');
				}

				$this->imported[] = $data;
				$this->actors[] = $actor;

				return new \stdClass();
			}
		};
	}//end taskLayer()

	/**
	 * An in-memory ObjectService keyed by schema slug.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Objects per schema.
	 *
	 * @return object The store.
	 */
	private static function store(array $rows): object {
		return new class($rows) {
			/**
			 * Hold the rows.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $rows Objects per schema.
			 */
			public function __construct(private readonly array $rows) {
			}//end __construct()

			/**
			 * Every object of one schema.
			 *
			 * @param string               $register      The register.
			 * @param string               $schema        The schema slug.
			 * @param array<string, mixed> $filters       Ignored.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>> The objects.
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) OpenRegister's signature.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->rows[$schema] ?? []);
			}
		};
	}//end store()
}//end class
