<?php

/**
 * The partner migration command: the summary it prints and the exit code it answers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\MigratePartnersCommand;
use OCA\Dossiq\Service\PartnerMigrationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Dossiq\Command\MigratePartnersCommand
 */
class MigratePartnersCommandTest extends TestCase {

	/**
	 * Run the command over a migration that answers $summary, or throws it.
	 *
	 * @param array<string, mixed>|RuntimeException $summary The summary, or the failure.
	 *
	 * @return CommandTester The tester after the run.
	 */
	private function runCommand(array|RuntimeException $summary): CommandTester {
		$service = $this->createMock(PartnerMigrationService::class);
		$call    = $service->expects($this->once())->method('migrate');
		if ($summary instanceof RuntimeException) {
			$call->willThrowException($summary);
		} else {
			$call->willReturn($summary);
		}

		$tester = new CommandTester(new MigratePartnersCommand(migrationService: $service));
		$tester->execute([]);

		return $tester;
	}//end runCommand()

	/**
	 * The command is registered under its occ name.
	 *
	 * @return void
	 */
	public function testItIsNamed(): void {
		$command = new MigratePartnersCommand(migrationService: $this->createMock(PartnerMigrationService::class));

		self::assertSame('dossiq:migrate-partners', $command->getName());
		self::assertStringContainsString('idempotent', $command->getDescription());
	}//end testItIsNamed()

	/**
	 * A clean run prints the counts and every mapping, and succeeds.
	 *
	 * @return void
	 */
	public function testACleanRunPrintsTheCountsAndMappings(): void {
		$tester = $this->runCommand(
			[
				'total' => 3,
				'migrated' => 2,
				'skipped' => 1,
				'failed' => 0,
				'mappings' => [
					['partner' => 'p-1', 'organisation' => 'o-1'],
					['partner' => 'p-2', 'organisation' => 'o-2'],
				],
			]
		);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertSame(
			"dossiq:migrate-partners done\n  total    = 3\n  migrated = 2\n  skipped  = 1\n  failed   = 0\n  p-1 -> o-1\n  p-2 -> o-2\n",
			$tester->getDisplay()
		);
	}//end testACleanRunPrintsTheCountsAndMappings()

	/**
	 * A run with a failed partner still prints the summary, and fails.
	 *
	 * @return void
	 */
	public function testAFailedPartnerFailsTheRun(): void {
		$tester = $this->runCommand(['total' => 1, 'migrated' => 0, 'skipped' => 0, 'failed' => 1, 'mappings' => []]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('  failed   = 1', $tester->getDisplay());
	}//end testAFailedPartnerFailsTheRun()

	/**
	 * A migration that throws is reported with its message, and fails.
	 *
	 * @return void
	 */
	public function testAThrowingMigrationIsReported(): void {
		$tester = $this->runCommand(new RuntimeException('OpenRegister is unavailable'));

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertSame("Partner migration failed: OpenRegister is unavailable\n", $tester->getDisplay());
	}//end testAThrowingMigrationIsReported()
}//end class
