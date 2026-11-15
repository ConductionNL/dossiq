<?php

/**
 * The besluit migration command: dry run or commit, a blocked run, and the detach report.
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

use OCA\Dossiq\Command\MigrateBesluitenToDecidiqCommand;
use OCA\Dossiq\Service\BesluitMigrationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Dossiq\Command\MigrateBesluitenToDecidiqCommand
 */
class MigrateBesluitenToDecidiqCommandTest extends TestCase {

	/**
	 * A summary the migration answers with.
	 *
	 * @param array<string, mixed> $overrides The fields that differ.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function summary(array $overrides=[]): array {
		return array_merge(['status' => 'ok', 'message' => '', 'total' => 4, 'migrated' => 3, 'skipped' => 1, 'failed' => 0, 'detached' => false], $overrides);
	}//end summary()

	/**
	 * Run the command over a migration that must receive $commit and answers $summary, or throws it.
	 *
	 * @param array<string, mixed>|RuntimeException $summary The summary, or the failure.
	 * @param bool                                  $commit  The commit flag the migration must receive.
	 *
	 * @return CommandTester The tester after the run.
	 */
	private function runCommand(array|RuntimeException $summary, bool $commit): CommandTester {
		$service = $this->createMock(BesluitMigrationService::class);
		$call    = $service->expects($this->once())->method('migrate')->with($commit);
		if ($summary instanceof RuntimeException) {
			$call->willThrowException($summary);
		} else {
			$call->willReturn($summary);
		}

		$tester = new CommandTester(new MigrateBesluitenToDecidiqCommand(migrationService: $service));
		$input  = [];
		if ($commit === true) {
			$input['--commit'] = true;
		}

		$tester->execute($input);

		return $tester;
	}//end runCommand()

	/**
	 * Without --commit it is a dry run that says how to write.
	 *
	 * @return void
	 */
	public function testWithoutCommitItIsADryRun(): void {
		$tester = $this->runCommand(summary: $this->summary(), commit: false);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertSame(
			"dossiq:migrate-besluiten (dry run, nothing written)\n"
			."  besluiten found = 4\n  to migrate      = 3\n  already there   = 1\n  failed          = 0\n"
			."  Re-run with --commit to write.\n",
			$tester->getDisplay()
		);
	}//end testWithoutCommitItIsADryRun()

	/**
	 * A dry run with nothing to migrate does not suggest a commit.
	 *
	 * @return void
	 */
	public function testADryRunWithNothingToMigrateSuggestsNothing(): void {
		$tester = $this->runCommand(summary: $this->summary(['migrated' => 0, 'skipped' => 4]), commit: false);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringNotContainsString('Re-run', $tester->getDisplay());
	}//end testADryRunWithNothingToMigrateSuggestsNothing()

	/**
	 * A committed run that detached the schema says so.
	 *
	 * @return void
	 */
	public function testACommittedRunReportsTheDetach(): void {
		$tester = $this->runCommand(summary: $this->summary(['detached' => true]), commit: true);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		$display = $tester->getDisplay();
		self::assertStringStartsWith('dossiq:migrate-besluiten (committed)', $display);
		self::assertStringContainsString('`decision_schema` removed; besluiten now resolve to decidiq.', $display);
		self::assertStringNotContainsString('Re-run', $display);
	}//end testACommittedRunReportsTheDetach()

	/**
	 * A committed run that kept the schema explains why, and a failed besluit fails the run.
	 *
	 * @return void
	 */
	public function testACommittedRunThatKeptTheSchemaAndFailedOne(): void {
		$tester = $this->runCommand(summary: $this->summary(['failed' => 1]), commit: true);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		$display = $tester->getDisplay();
		self::assertStringContainsString('  failed          = 1', $display);
		self::assertStringContainsString('`decision_schema` kept: not every besluit is across yet', $display);
		self::assertStringNotContainsString('removed', $display);
	}//end testACommittedRunThatKeptTheSchemaAndFailedOne()

	/**
	 * A blocked run prints the migration's message and succeeds, without the report.
	 *
	 * @return void
	 */
	public function testABlockedRunPrintsItsMessage(): void {
		$tester = $this->runCommand(summary: $this->summary(['status' => 'blocked', 'message' => 'decidiq is not installed', 'failed' => 2]), commit: true);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertSame("decidiq is not installed\n", $tester->getDisplay());
	}//end testABlockedRunPrintsItsMessage()

	/**
	 * A migration that throws is reported with its message, and fails.
	 *
	 * @return void
	 */
	public function testAThrowingMigrationIsReported(): void {
		$tester = $this->runCommand(summary: new RuntimeException('no register'), commit: false);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertSame("Besluit migration failed: no register\n", $tester->getDisplay());
	}//end testAThrowingMigrationIsReported()
}//end class
