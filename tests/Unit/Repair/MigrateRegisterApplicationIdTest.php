<?php

/**
 * MigrateRegisterApplicationId unit tests
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateRegisterApplicationId;
use OCA\OpenRegister\Service\SchemaApplicationMigrator;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../Stubs/OpenRegister/SchemaApplicationMigratorStub.php';

/**
 * Covers the repair step that re-points the register onto the dossiq app id.
 */
class MigrateRegisterApplicationIdTest extends TestCase {

	/**
	 * Reset the stub between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		SchemaApplicationMigrator::$calledWith = [];
		SchemaApplicationMigrator::$throws     = false;
		SchemaApplicationMigrator::$outcome    = [
			'ok'         => true,
			'reason'     => 'migrated',
			'collisions' => [],
			'schemas'    => 0,
			'registers'  => 0,
		];

	}//end setUp()


	/**
	 * Build the step with a container that returns the stub migrator.
	 *
	 * @return MigrateRegisterApplicationId The step under test.
	 */
	private function step(): MigrateRegisterApplicationId {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new SchemaApplicationMigrator());

		return new MigrateRegisterApplicationId($container, $this->createMock(LoggerInterface::class));

	}//end step()


	/**
	 * The migration is requested for procest -> dossiq, in that direction.
	 *
	 * The direction is the whole point: reversed, it would move a correctly
	 * migrated estate back onto the retired id.
	 *
	 * @return void
	 */
	public function testMigratesFromProcestToDossiq(): void {
		SchemaApplicationMigrator::$outcome['schemas'] = 12;

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame(['procest', 'dossiq'], SchemaApplicationMigrator::$calledWith);

	}//end testMigratesFromProcestToDossiq()


	/**
	 * A successful move reports the counts.
	 *
	 * @return void
	 */
	public function testReportsWhatItMoved(): void {
		SchemaApplicationMigrator::$outcome['schemas']   = 12;
		SchemaApplicationMigrator::$outcome['registers'] = 1;

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('Moved 12 schema(s) and 1 register(s)'));

		$this->step()->run($output);

	}//end testReportsWhatItMoved()


	/**
	 * A second run reports "already migrated" rather than a bare success.
	 *
	 * Zero moved rows is the idempotent case, and saying so is what keeps it
	 * distinguishable from a run that did work.
	 *
	 * @return void
	 */
	public function testSecondRunSaysAlreadyMigrated(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('already migrated'));

		$this->step()->run($output);

	}//end testSecondRunSaysAlreadyMigrated()


	/**
	 * A refusal names the colliding slugs and the command that resolves them.
	 *
	 * @return void
	 */
	public function testRefusalNamesTheCollidingSlugs(): void {
		SchemaApplicationMigrator::$outcome = [
			'ok'         => false,
			'reason'     => 'collisions',
			'collisions' => ['case', 'casetype'],
			'schemas'    => 0,
			'registers'  => 0,
		];

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with($this->logicalAnd(
				$this->stringContains('case, casetype'),
				$this->stringContains('openregister:schemas:dedup')
			));

		$this->step()->run($output);

	}//end testRefusalNamesTheCollidingSlugs()


	/**
	 * A throwing migrator warns instead of aborting the upgrade.
	 *
	 * Letting this bubble would fail the whole app upgrade over a migration
	 * that the next run can simply retry — the estate is untouched either way.
	 *
	 * @return void
	 */
	public function testAThrowingMigratorDoesNotAbortTheUpgrade(): void {
		SchemaApplicationMigrator::$throws = true;

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with($this->stringContains('database is on fire'));

		$this->step()->run($output);

	}//end testAThrowingMigratorDoesNotAbortTheUpgrade()


}//end class
