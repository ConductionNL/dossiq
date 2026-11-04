<?php

/**
 * Example data for DqTaak: the hearing task waits in a running bezwaar flow and Pieter has picked it up.
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
 * Hoorzitting inplannen names the bezwaar flow's waiting run, and carries Pieter's claim.
 */
class ZuiddrechtHearingWaitsInBezwaarFlowTest extends TestCase {

	/**
	 * The main fragment: a task quotes a flow of its own fragment only.
	 *
	 * @return array<string, mixed> The fragment.
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
	}//end fragment()

	/**
	 * The bezwaar flow waits on its user-task step Hoorzitting, on the Havenstraat objection.
	 *
	 * @return void
	 */
	public function testTheBezwaarFlowWaitsOnTheHearingStep(): void {
		$flows = array_column($this->fragment()['flows'], 'data', 'key');
		$this->assertArrayHasKey(key: 'flow-bezwaar', array: $flows);

		$steps = array_column($flows['flow-bezwaar']['nodes'], 'type', 'id');
		$this->assertSame(expected: 'openregister.user-task', actual: $steps['hoorzitting']);

		$runs = $flows['flow-bezwaar']['runs'];
		$this->assertCount(expectedCount: 1, haystack: $runs);
		$this->assertSame(expected: 'hoorzitting-havenstraat', actual: $runs[0]['key']);
		$this->assertSame(expected: 'suspended', actual: $runs[0]['status']);
		$this->assertSame(expected: 'hoorzitting', actual: $runs[0]['node']);
		$this->assertSame(expected: '{{object:bezwaar-parkeerboete-havenstraat.id}}', actual: $runs[0]['subject']);
	}//end testTheBezwaarFlowWaitsOnTheHearingStep()

	/**
	 * The hearing task names that run and step, and Pieter, its handler, picked it up yesterday at 09:05.
	 *
	 * @return void
	 */
	public function testTheHearingTaskWaitsInTheRunAndIsClaimedByItsHandler(): void {
		$tasks = array_column($this->fragment()['tasks'], 'data', 'key');
		$task  = $tasks['hoorzitting-inplannen'];

		$this->assertSame(expected: '{{flow:flow-bezwaar.runs.hoorzitting-havenstraat}}', actual: $task['runUuid']);
		$this->assertSame(expected: 'hoorzitting', actual: $task['nodeId']);
		$this->assertSame(expected: $task['assignee'], actual: $task['claimedBy']);
		$this->assertSame(expected: '{{datetime:-1 09:05}}', actual: $task['claimedAt']);
	}//end testTheHearingTaskWaitsInTheRunAndIsClaimedByItsHandler()
}//end class
