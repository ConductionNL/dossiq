<?php

/**
 * Unit test for IntakeSourceRegister.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
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

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\IntakeSourceRegister;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeSourceRegister
 */
class IntakeSourceRegisterTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$register = 'register-value';

		$value = new IntakeSourceRegister(
			objectService: $objectService,
			register: $register,
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: $register, actual: $value->register);
	}//end testEveryPropertyReadsBack()
}//end class
