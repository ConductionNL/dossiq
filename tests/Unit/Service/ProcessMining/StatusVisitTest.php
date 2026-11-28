<?php

/**
 * One status visit: the record, the record after it, and the moments read from either.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\ProcessMining\StatusVisit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\ProcessMining\StatusVisit
 */
class StatusVisitTest extends TestCase {

	/**
	 * A visit holds its record and the next one, and reads its status, actor and moments.
	 *
	 * @return void
	 */
	public function testAVisitReadsItsRecordAndTheNext(): void {
		$records = [
			['statusType' => 'intake', 'actor' => '  behandelaar-1 ', 'createdAt' => '2026-01-01T09:00:00+00:00'],
			['statusType' => 7, '@self' => ['created' => '2026-01-03T09:00:00+00:00']],
		];

		$visit = new StatusVisit(records: $records, index: 0);

		$this->assertSame(expected: $records[0], actual: $visit->record);
		$this->assertSame(expected: $records[1], actual: $visit->next);
		$this->assertSame(expected: 'intake', actual: $visit->statusId());
		$this->assertSame(expected: 'behandelaar-1', actual: $visit->actor());
		$this->assertSame(expected: '2026-01-01T09:00:00+00:00', actual: $visit->enteredMoment());
		$this->assertSame(expected: '2026-01-03T09:00:00+00:00', actual: $visit->nextMoment());
	}//end testAVisitReadsItsRecordAndTheNext()

	/**
	 * The last visit has no next record and no next moment; missing fields read as empty.
	 *
	 * @return void
	 */
	public function testTheLastVisitHasNoNext(): void {
		$records = [
			['statusType' => 'intake', 'createdAt' => '2026-01-01'],
			['statusType' => 7],
		];

		$visit = new StatusVisit(records: $records, index: 1);

		$this->assertNull($visit->next);
		$this->assertNull($visit->nextMoment());
		$this->assertNull($visit->enteredMoment());
		$this->assertSame(expected: '7', actual: $visit->statusId(), message: 'a numeric status id reads as a string');
		$this->assertSame(expected: '', actual: $visit->actor());
		$this->assertSame(expected: '', actual: (new StatusVisit(records: [[]], index: 0))->statusId());
	}//end testTheLastVisitHasNoNext()

	/**
	 * The moment is createdAt, else @self.created, else @self.createdAt.
	 *
	 * @return void
	 */
	public function testTheMomentFallsBackThroughTheMetadata(): void {
		$records = [
			['createdAt' => 'top', '@self' => ['created' => 'self-created', 'createdAt' => 'self-createdAt']],
			['@self' => ['created' => 'self-created', 'createdAt' => 'self-createdAt']],
			['@self' => ['createdAt' => 'self-createdAt']],
		];

		$this->assertSame(expected: 'top', actual: (new StatusVisit(records: $records, index: 0))->enteredMoment());
		$this->assertSame(expected: 'self-created', actual: (new StatusVisit(records: $records, index: 0))->nextMoment());
		$this->assertSame(expected: 'self-createdAt', actual: (new StatusVisit(records: $records, index: 1))->nextMoment());
	}//end testTheMomentFallsBackThroughTheMetadata()
}//end class
