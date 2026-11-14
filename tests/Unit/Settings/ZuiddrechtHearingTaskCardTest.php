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

	/**
	 * The task was created when its run was (5 okt 16:20 on the board's 7 okt), before Pieter claimed it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hearing-task-carries-its-creation-moment/specs/example-set-zuiddrecht-data/spec.md#requirement-the-hearing-task-carries-the-moment-its-run-created-it
	 */
	public function testTheTaskWasCreatedWithItsRun(): void {
		$set  = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$runs = array_column($set['flows'][array_search('flow-bezwaar', array_column($set['flows'], 'key'), true)]['data']['runs'] ?? [], 'created', 'key');

		$this->assertSame(expected: '{{datetime:-2 16:20}}', actual: $this->hearing()['createdAt']);
		$this->assertSame(expected: $runs['hoorzitting-havenstraat'] ?? null, actual: $this->hearing()['createdAt']);
		$this->assertSame(expected: '{{datetime:-1 09:05}}', actual: $this->hearing()['claimedAt']);
	}//end testTheTaskWasCreatedWithItsRun()
}//end class
