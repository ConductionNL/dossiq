<?php

/**
 * Unit test for InvoiceMonth.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\InvoiceMonth;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\InvoiceMonth
 */
class InvoiceMonthTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$tenantId = 'tenantId-value';
		$period = 'period-value';

		$value = new InvoiceMonth(
			tenantId: $tenantId,
			period: $period,
		);

		$this->assertSame(expected: $tenantId, actual: $value->tenantId);
		$this->assertSame(expected: $period, actual: $value->period);
	}//end testEveryPropertyReadsBack()
}//end class
