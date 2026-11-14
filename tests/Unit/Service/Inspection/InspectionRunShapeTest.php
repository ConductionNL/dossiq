<?php

/**
 * Unit tests for the inspection run shapes: a completed engine task is read
 * back as the run the inspection panel shows, and a carried-over source
 * object is referenced by its schema and id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionRunCarryOver;
use OCA\Dossiq\Service\Inspection\InspectionRunService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Inspection\InspectionRunService
 * @covers \OCA\Dossiq\Service\Inspection\InspectionRunCarryOver
 *
 * @uses \OCA\Dossiq\Service\Inspection\InspectionAnswers
 * @uses \OCA\Dossiq\Service\ChecklistService
 */
class InspectionRunShapeTest extends TestCase {

	private function service(): InspectionRunService {
		return new InspectionRunService($this->createMock(SettingsService::class), $this->createMock(EngineInboxQuery::class));
	}//end service()

	public function testACompletedTaskIsReadBackAsTheRunThePanelShows(): void {
		$run = $this->service()->asRun(
			[
				'uuid' => 'task-1',
				'objectUuid' => 'case-1',
				'templateId' => 'tpl-1',
				'templateVersion' => 3,
				'completedBy' => 'alice',
				'assignee' => 'bob',
				'completedAt' => '2026-11-12T10:00:00Z',
				'created' => '2026-11-01',
				'outcome' => 'afgekeurd',
				'responses' => [
					['item' => 'i-1', 'value' => 'ja'],
					['item' => 'i-2', 'value' => 'nee', 'numericValue' => 12.5],
					['item' => 'i-3', 'value' => 'nvt'],
				],
				'comment' => 'Brandblusser verlopen.',
				'metadata' => ['location' => ['lat' => 52.1, 'lon' => 5.1]],
				'evidence' => [['file' => 'p-1']],
				'state' => 'completed',
			]
		);

		$this->assertSame(
			[
				'id' => 'task-1',
				'case' => 'case-1',
				'checklist' => 'tpl-1',
				'templateVersion' => 3,
				'inspector' => 'alice',
				'completedBy' => 'alice',
				'inspectionDate' => '2026-11-12T10:00:00Z',
				'result' => 'afgekeurd',
				'failedItems' => 1,
				'items' => [
					['item' => 'i-1', 'value' => 'ja', 'result' => 'pass'],
					['item' => 'i-2', 'value' => 'nee', 'numericValue' => 12.5, 'result' => 'fail', 'measurement' => 12.5],
					['item' => 'i-3', 'value' => 'nvt', 'result' => 'nvt'],
				],
				'remarks' => 'Brandblusser verlopen.',
				'location' => ['lat' => 52.1, 'lon' => 5.1],
				'photos' => [['file' => 'p-1']],
				'state' => 'completed',
			],
			$run
		);
	}//end testACompletedTaskIsReadBackAsTheRunThePanelShows()

	public function testAnOpenOrCarriedOverTaskFallsBackToTheAssigneeAndTheEarliestKnownMoment(): void {
		$service = $this->service();

		$carried = $service->asRun(['assignee' => 'bob', 'metadata' => ['legacyCompletedAt' => '2024-05-01'], 'created' => '2026-11-01']);
		$open = $service->asRun(['assignee' => 'bob', 'created' => '2026-11-01', 'responses' => 'none', 'metadata' => 'none']);

		$this->assertSame(['bob', '', '2024-05-01'], [$carried['inspector'], $carried['completedBy'], $carried['inspectionDate']]);
		$this->assertSame('2026-11-01', $open['inspectionDate']);
		$this->assertSame([0, [], null, [], null], [$open['failedItems'], $open['items'], $open['location'], $open['photos'], $open['templateVersion']]);
		$this->assertSame(['', '', '', ''], [$open['id'], $open['case'], $open['checklist'], $open['state']]);
	}//end testAnOpenOrCarriedOverTaskFallsBackToTheAssigneeAndTheEarliestKnownMoment()

	public function testACarriedOverObjectIsReferencedBySchemaAndId(): void {
		$carryOver = new InspectionRunCarryOver();

		$this->assertSame('inspectie/s-1', $carryOver->legacyRef('inspectie', ['@self' => ['id' => 's-1'], 'id' => 'other']));
		$this->assertSame('inspectie/o-1', $carryOver->legacyRef('inspectie', ['@self' => ['id' => ''], 'id' => 'o-1']));
		$this->assertSame('inspectie/u-1', $carryOver->legacyRef('inspectie', ['@self' => 'x', 'uuid' => 'u-1']));
		$this->assertSame('inspectie/', $carryOver->legacyRef('inspectie', []));
	}//end testACarriedOverObjectIsReferencedBySchemaAndId()
}//end class
