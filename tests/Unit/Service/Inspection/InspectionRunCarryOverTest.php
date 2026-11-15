<?php

/**
 * Carry-over tests: the three older run shapes as task fields.
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

use OCA\Dossiq\Service\Inspection\InspectionRunCarryOver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see InspectionRunCarryOver}.
 *
 * @covers \OCA\Dossiq\Service\Inspection\InspectionRunCarryOver
 * @uses   \OCA\Dossiq\Service\Inspection\InspectionAnswers
 * @uses   \OCA\Dossiq\Service\ChecklistService
 * @uses   \OCA\Dossiq\Service\Support\ChecklistPayloadReader
 */
final class InspectionRunCarryOverTest extends TestCase {

	/**
	 * Stack A: a completed task, answers in the template's words, location as an address.
	 *
	 * @return void
	 */
	public function testAStackAReportBecomesACompletedTask(): void {
		$carry = new InspectionRunCarryOver();
		$report = [
			'@self' => ['id' => 'r-1'],
			'case' => 'case-1',
			'checklist' => 'a-1',
			'inspector' => 'inspecteur-a',
			'inspectionDate' => '2026-09-01T10:00:00+02:00',
			'location' => 'Dorpsstraat 1',
			'result' => 'partly_conform',
			'items' => [['itemId' => 'Wapening', 'result' => 'fail', 'photos' => ['5']], ['itemId' => 'Maat', 'result' => 'pass']],
			'photos' => ['6'],
			'remarks' => 'scheur',
		];
		$template = ['@self' => ['id' => 't-1'], 'name' => 'Fundering', 'version' => 1, 'sections' => []];

		$this->assertSame('inspectieChecklist/a-1', $carry->templateRef(schema: 'inspectieRapport', object: $report));
		$data = $carry->taskData(schema: 'inspectieRapport', object: $report, template: $template);

		$this->assertSame('inspection', $data['kind']);
		$this->assertSame('case-1', $data['objectUuid']);
		$this->assertSame('completed', $data['state']);
		$this->assertSame('partly_conform', $data['outcome']);
		$this->assertSame('inspecteur-a', $data['assignee']);
		$this->assertSame('t-1', $data['templateId']);
		$this->assertSame('nee', $data['responses'][0]['value']);
		$this->assertSame(['5', '6'], $data['evidence']);
		$this->assertSame('scheur', $data['comment']);
		$this->assertSame('inspectieRapport/r-1', $data['metadata']['legacyRef']);
		$this->assertSame('2026-09-01T10:00:00+02:00', $data['metadata']['legacyCompletedAt']);
		$this->assertSame(['address' => 'Dorpsstraat 1', 'source' => 'manual'], $data['metadata']['location']);
	}//end testAStackAReportBecomesACompletedTask()

	/**
	 * Stack B: its own snapshot wins; a draft run arrives active; an external inspector stays external.
	 *
	 * @return void
	 */
	public function testAStackBDraftRunArrivesActiveAndExternal(): void {
		$carry = new InspectionRunCarryOver();
		$run = [
			'id' => 'b-1',
			'case' => 'case-2',
			'template' => 't-9',
			'templateVersion' => 4,
			'templateSnapshot' => ['name' => 'Brand', 'version' => 4, 'sections' => []],
			'assignedInspectorRef' => 'party-7',
			'status' => 'in_execution',
			'responses' => [['itemId' => 'q1', 'value' => 'ja']],
			'location' => ['lat' => 52.1, 'lon' => 5.3],
			'inspection' => 'insp-1',
		];

		$data = $carry->taskData(schema: 'inspectionChecklistRun', object: $run, template: null);

		$this->assertSame('active', $data['state']);
		$this->assertArrayNotHasKey('outcome', $data);
		$this->assertSame(['party-7', 'external'], [$data['assignee'], $data['performerType']]);
		$this->assertSame('t-9', $data['templateId']);
		$this->assertSame(4, $data['templateVersion']);
		$this->assertSame('Brand', $data['templateSnapshot']['name']);
		$this->assertSame(['lat' => 52.1, 'lon' => 5.3], $data['metadata']['location']);
		$this->assertSame('insp-1', $data['metadata']['inspection']);
	}//end testAStackBDraftRunArrivesActiveAndExternal()

	/**
	 * Stack C: a missing outcome is decided by the rule; its checklist resolves through the fold.
	 *
	 * @return void
	 */
	public function testAStackCResultWithoutAnOutcomeGetsTheRulesVerdict(): void {
		$carry = new InspectionRunCarryOver();
		$result = ['id' => 'c-1', 'case' => 'case-3', 'checklist' => 'old-1', 'completedBy' => 'u2', 'completedAt' => '2026-09-03T09:00:00Z', 'answers' => [['itemRef' => 'i-1', 'value' => 'non_conform']]];
		$template = ['@self' => ['id' => 't-3'], 'name' => 'C', 'version' => 1, 'sections' => [['items' => [['id' => 'i-1', 'responseType' => 'yes_no_na']]]]];

		$this->assertSame('inspectionChecklist/old-1', $carry->templateRef(schema: 'inspectionResult', object: $result));
		$data = $carry->taskData(schema: 'inspectionResult', object: $result, template: $template);

		$this->assertSame('non_conform', $data['outcome']);
		$this->assertSame('u2', $data['assignee']);
		$this->assertSame('t-3', $data['templateId']);
		$this->assertSame('2026-09-03T09:00:00Z', $data['metadata']['legacyCompletedAt']);
	}//end testAStackCResultWithoutAnOutcomeGetsTheRulesVerdict()
}//end class
