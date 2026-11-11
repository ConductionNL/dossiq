<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionTemplateMapper;
use PHPUnit\Framework\TestCase;

/**
 * The whole template `fromStackC` builds: each kind of item, the ids, the status and the case type.
 */
class InspectionStackCSnapshotTest extends TestCase {

	/**
	 * A row by uuid, inline items with and without ids, an unreadable value and a gone uuid.
	 *
	 * @return void
	 */
	public function testMixedItems(): void {
		$converted = (new InspectionTemplateMapper())->fromStackC(
			checklist: [
				'id' => 'c-1',
				'name' => 'N',
				'caseType' => 'ct-old',
				'active' => true,
				'items' => ['i-1', ['uuid' => 'u-2', 'label' => 'L2', 'type' => 'yesno'], ['question' => 'Q3', 'label' => 'ignored'], 7, 'gone', ['id' => 'x-5']],
			],
			rows: ['i-1' => ['question' => 'Q1', 'type' => 'number', 'id' => 'row-id']],
		);

		self::assertSame(json_decode('{"template":{"name":"N","version":1,"status":"active","active":true,"sections":[{"order":1,"name":"N","items":[{"id":"i-1","order":1,"label":"Q1","responseType":"text","required":false,"photoRequired":"nooit"},{"id":"u-2","order":2,"label":"L2","responseType":"text","required":false,"photoRequired":"nooit"},{"id":"item-3","order":3,"label":"Q3","responseType":"text","required":false,"photoRequired":"nooit"},{"id":"x-5","order":6,"label":"","responseType":"text","required":false,"photoRequired":"nooit"}]}],"legacyRef":"inspectionChecklist/c-1","caseType":"ct-old"},"unresolved":["7","gone"]}', true), $converted);
	}//end testMixedItems()

	/**
	 * Not `true` is retired, caseTypeRef wins over caseType, and items that are no list are none.
	 *
	 * @return void
	 */
	public function testRetiredWithoutItems(): void {
		$converted = (new InspectionTemplateMapper())->fromStackC(
			checklist: ['uuid' => 'c-2', 'caseTypeRef' => 'ct-new', 'caseType' => 'ct-old', 'active' => 'yes', 'items' => 'not-a-list'],
			rows: [],
		);

		self::assertSame(json_decode('{"template":{"name":"","version":1,"status":"retired","active":false,"sections":[{"order":1,"name":"","items":[]}],"legacyRef":"inspectionChecklist/c-2","caseType":"ct-new"},"unresolved":[]}', true), $converted);
	}//end testRetiredWithoutItems()

	/**
	 * An empty checklist still makes a template, without a case type.
	 *
	 * @return void
	 */
	public function testEmpty(): void {
		self::assertSame(json_decode('{"template":{"name":"","version":1,"status":"retired","active":false,"sections":[{"order":1,"name":"","items":[]}],"legacyRef":"inspectionChecklist/"},"unresolved":[]}', true), (new InspectionTemplateMapper())->fromStackC(checklist: [], rows: []));
	}//end testEmpty()
}//end class
