<?php

/**
 * Example data for DqTaken: Anouk Bakker's open task "Conceptbesluit nalezen"
 * on the tender case Aanbesteding jeugdzorg 2026, due two days on, so the
 * task inbox lists it as the board draws it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The concept decision task of the Zuiddrecht fragment.
 */
class ZuiddrechtConceptDecisionTaskTest extends TestCase {

	/**
	 * The fragment's tasks, keyed by their key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function tasks(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);

		return array_column($set['tasks'], null, 'key');
	}//end tasks()

	/**
	 * A control: the tree advice task the board lists beside it is there.
	 *
	 * @return void
	 */
	public function testTheTreeAdviceTaskIsThere(): void {
		$this->assertArrayHasKey(key: 'advies-boomdeskundige', array: $this->tasks());
	}//end testTheTreeAdviceTaskIsThere()

	/**
	 * Anouk reads the concept decision of the jeugdzorg tender, high priority,
	 * in progress, due two days on.
	 *
	 * @return void
	 */
	public function testAnoukReadsTheConceptDecision(): void {
		$tasks = $this->tasks();
		$this->assertArrayHasKey(key: 'conceptbesluit-nalezen', array: $tasks);

		$data = $tasks['conceptbesluit-nalezen']['data'];
		$this->assertSame(expected: 'Conceptbesluit nalezen', actual: $data['title']);
		$this->assertSame(expected: 'b84067ac-35ec-55b5-bf17-975206ce32f2', actual: $data['objectUuid']);
		$this->assertSame(expected: 'active', actual: $data['state']);
		$this->assertSame(expected: 'high', actual: $data['priority']);
		$this->assertSame(expected: '{{date:2}}', actual: $data['dueAt']);
		$this->assertSame(expected: '{{cast:anouk.uid}}', actual: $data['assignee']);
	}//end testAnoukReadsTheConceptDecision()
}//end class
