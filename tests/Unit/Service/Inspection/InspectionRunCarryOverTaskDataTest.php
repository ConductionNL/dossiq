<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionRunCarryOver;
use PHPUnit\Framework\TestCase;

/**
 * The whole task `taskData` builds for each source, field by field and in order.
 */
class InspectionRunCarryOverTaskDataTest extends TestCase {

	/**
	 * A finished report with its template: a snapshot, a version, a start, a trimmed comment and an assignee.
	 *
	 * @return void
	 */
	public function testAFinishedReport(): void {
		$data = (new InspectionRunCarryOver())->taskData(
			schema: 'inspectieRapport',
			object: ['@self' => ['id' => 'r-1'], 'case' => 'case-1', 'checklist' => 'a-1', 'inspector' => 'insp', 'inspectionDate' => '2026-09-01T10:00:00+02:00', 'result' => 'conform', 'items' => [['itemId' => 'Maat', 'result' => 'pass']], 'remarks' => '  scheur  '],
			template: ['@self' => ['id' => 't-1'], 'name' => 'Fundering', 'version' => 2, 'sections' => []],
		);

		self::assertSame(json_decode('{"title":"Inspectie: Fundering","kind":"inspection","appId":"dossiq","objectUuid":"case-1","state":"completed","outcome":"conform","responses":[{"itemId":"Maat","value":"ja"}],"evidence":[],"templateId":"t-1","metadata":{"legacyRef":"inspectieRapport/r-1","legacyCompletedAt":"2026-09-01T10:00:00+02:00"},"templateSnapshot":{"@self":{"id":"t-1"},"name":"Fundering","version":2,"sections":[]},"templateVersion":2,"startAt":"2026-09-01T10:00:00+02:00","comment":"scheur","assignee":"insp"}', true), $data);
	}//end testAFinishedReport()

	/**
	 * A run still in execution arrives active, without an outcome, a snapshot or a blank comment.
	 *
	 * @return void
	 */
	public function testAnOpenRun(): void {
		$data = (new InspectionRunCarryOver())->taskData(
			schema: 'inspectionChecklistRun',
			object: ['id' => 'run-1', 'case' => 'case-2', 'template' => 'tpl-2', 'status' => 'in_execution', 'startedAt' => '2026-09-02T09:00:00+00:00', 'responses' => [], 'remarks' => ' '],
			template: null,
		);

		self::assertSame(json_decode('{"title":"Inspectie: ","kind":"inspection","appId":"dossiq","objectUuid":"case-2","state":"active","responses":[],"evidence":[],"templateId":"tpl-2","metadata":{"legacyRef":"inspectionChecklistRun/run-1"},"startAt":"2026-09-02T09:00:00+00:00"}', true), $data);
	}//end testAnOpenRun()

	/**
	 * A bare result gets the rules' verdict and nothing optional.
	 *
	 * @return void
	 */
	public function testABareResult(): void {
		$data = (new InspectionRunCarryOver())->taskData(
			schema: 'inspectionResult',
			object: ['id' => 'res-1', 'checklist' => 'c-3', 'status' => 'completed', 'answers' => []],
			template: null,
		);

		self::assertSame(json_decode('{"title":"Inspectie: ","kind":"inspection","appId":"dossiq","objectUuid":"","state":"completed","outcome":"conform","responses":[],"evidence":[],"templateId":"c-3","metadata":{"legacyRef":"inspectionResult/res-1"}}', true), $data);
	}//end testABareResult()
}//end class
