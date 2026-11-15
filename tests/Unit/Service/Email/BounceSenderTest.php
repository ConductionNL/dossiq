<?php

/**
 * Unit test for BounceSender.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\BounceSender;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Email\BounceSender
 */
class BounceSenderTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$fromAddress = 'fromAddress-value';
		$fromName = 'fromName-value';

		$value = new BounceSender(
			fromAddress: $fromAddress,
			fromName: $fromName,
		);

		$this->assertSame(expected: $fromAddress, actual: $value->fromAddress);
		$this->assertSame(expected: $fromName, actual: $value->fromName);
	}//end testEveryPropertyReadsBack()
}//end class
