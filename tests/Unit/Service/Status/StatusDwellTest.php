<?php

/**
 * Unit test for StatusDwell.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use OCA\Dossiq\Service\Status\StatusDwell;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Status\StatusDwell
 */
class StatusDwellTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'caseId-value';
		$statusTypeId = 'statusTypeId-value';
		$maximumDwell = 102;
		$statusName = 'statusName-value';

		$value = new StatusDwell(
			caseId: $caseId,
			statusTypeId: $statusTypeId,
			maximumDwell: $maximumDwell,
			statusName: $statusName,
		);

		$this->assertSame(expected: $caseId, actual: $value->caseId);
		$this->assertSame(expected: $statusTypeId, actual: $value->statusTypeId);
		$this->assertSame(expected: $maximumDwell, actual: $value->maximumDwell);
		$this->assertSame(expected: $statusName, actual: $value->statusName);
	}//end testEveryPropertyReadsBack()
}//end class
