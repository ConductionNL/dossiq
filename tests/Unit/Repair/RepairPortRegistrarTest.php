<?php

/**
 * RepairPortRegistrar unit tests
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\DbValueMigrationPort;
use OCA\Dossiq\Repair\RepairPortRegistrar;
use OCA\Dossiq\Repair\ValueMigrationPort;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The value migration's storage seam is bound to the database port.
 *
 * @covers \OCA\Dossiq\Repair\RepairPortRegistrar
 */
final class RepairPortRegistrarTest extends TestCase {

	/**
	 * Exactly one alias: the port interface to its database implementation.
	 *
	 * @return void
	 */
	public function testBindsTheValueMigrationPortToTheDatabasePort(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())
			->method('registerServiceAlias')
			->with(ValueMigrationPort::class, DbValueMigrationPort::class);

		(new RepairPortRegistrar())->register(context: $context);
	}//end testBindsTheValueMigrationPortToTheDatabasePort()
}//end class
