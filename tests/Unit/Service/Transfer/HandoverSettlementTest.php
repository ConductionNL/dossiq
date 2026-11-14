<?php

/**
 * HandoverSettlement unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transfer
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

namespace OCA\Dossiq\Tests\Unit\Service\Transfer;

use OCA\Dossiq\Service\Transfer\HandoverSettlement;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HandoverSettlement.
 *
 * @covers \OCA\Dossiq\Service\Transfer\HandoverSettlement
 */
class HandoverSettlementTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new HandoverSettlement(
			transferId: 'transfer-1',
			status: 'rejected',
			actor: 'bob',
			reason: 'Niet ons team'
		);

		$this->assertSame(expected: 'transfer-1', actual: $value->transferId);
		$this->assertSame(expected: 'rejected', actual: $value->status);
		$this->assertSame(expected: 'bob', actual: $value->actor);
		$this->assertSame(expected: 'Niet ons team', actual: $value->reason);
	}//end testPropertiesReadBack()
}//end class
