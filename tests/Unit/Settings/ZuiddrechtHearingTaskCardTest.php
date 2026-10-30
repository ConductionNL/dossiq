<?php

/**
 * Example data for DqTaak: the hearing task's "Taak" card lists its facts
 * (handler, team, deadline, expiry, priority, requester, created) and no
 * description, as the board draws it.
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
 * The hearing task of the Zuiddrecht fragment.
 */
class ZuiddrechtHearingTaskCardTest extends TestCase {

	/**
	 * The hearing task's data.
	 *
	 * @return array<string, mixed>
	 */
	private function hearing(): array {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$tasks = array_column($set['tasks'], 'data', 'key');

		return $tasks['hoorzitting-inplannen'];
	}//end hearing()

	/**
	 * A control: the facts the board lists are filled.
	 *
	 * @return void
	 */
	public function testTheCardFactsAreFilled(): void {
		$task = $this->hearing();
		$this->assertSame(expected: '{{cast:anouk.uid}}', actual: $task['requester']);
		$this->assertSame(expected: '{{date:7}}', actual: $task['expiresAt']);
		$this->assertSame(expected: 'high', actual: $task['priority']);
	}//end testTheCardFactsAreFilled()

	/**
	 * The task's description is empty (an empty value, so a refresh clears a
	 * description an earlier install wrote), and the card opens on its facts.
	 *
	 * @return void
	 */
	public function testTheCardHasNoDescription(): void {
		$this->assertSame(expected: '', actual: $this->hearing()['description']);
	}//end testTheCardHasNoDescription()
}//end class
