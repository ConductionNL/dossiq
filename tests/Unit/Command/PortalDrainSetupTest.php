<?php

/**
 * PortalDrainSetup unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
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

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\PortalDrainSetup;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for PortalDrainSetup.
 *
 * @covers \OCA\Dossiq\Command\PortalDrainSetup
 */
class PortalDrainSetupTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$submitter = new stdClass();
		$admin = $this->createMock(originalClassName: IUser::class);
		$value = new PortalDrainSetup(
			objectService: $objectService,
			submitter: $submitter,
			admin: $admin,
			register: '5',
			caseSchema: '7',
			sourceSchema: '11'
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: $submitter, actual: $value->submitter);
		$this->assertSame(expected: $admin, actual: $value->admin);
		$this->assertSame(expected: '5', actual: $value->register);
		$this->assertSame(expected: '7', actual: $value->caseSchema);
		$this->assertSame(expected: '11', actual: $value->sourceSchema);
	}//end testPropertiesReadBack()
}//end class
