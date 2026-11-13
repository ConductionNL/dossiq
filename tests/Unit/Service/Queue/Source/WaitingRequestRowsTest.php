<?php

/**
 * Unit tests for WaitingRequestRows: which request rows still wait on the
 * reader, and where a row without a case leads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\Source\WaitingRequestRows;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\WaitingRequestRows
 */
class WaitingRequestRowsTest extends TestCase {

	public function testARowWaitsWhileItHasAnIdAndNoClosedStatus(): void {
		$rows = new WaitingRequestRows(closedStatuses: ['approved', 'rejected']);

		$this->assertTrue($rows->isWaiting('r1', ['status' => 'pending']));
		$this->assertTrue($rows->isWaiting('r1', []), 'A row with no status still waits.');
		$this->assertFalse($rows->isWaiting('r1', ['status' => ' Approved ']), 'Status is compared trimmed and case-blind.');
		$this->assertFalse($rows->isWaiting('r1', ['status' => 'rejected']));
		$this->assertFalse($rows->isWaiting('', ['status' => 'pending']), 'A row without an id cannot be opened.');
	}//end testARowWaitsWhileItHasAnIdAndNoClosedStatus()

	public function testARowWithoutACaseLeadsToTheCaseList(): void {
		$caseRoute = ['name' => 'CaseDetail', 'params' => ['id' => 'case-1']];

		$this->assertSame(['name' => 'Cases'], WaitingRequestRows::routeOf('', $caseRoute));
		$this->assertSame($caseRoute, WaitingRequestRows::routeOf('case-1', $caseRoute));
	}//end testARowWithoutACaseLeadsToTheCaseList()
}//end class
