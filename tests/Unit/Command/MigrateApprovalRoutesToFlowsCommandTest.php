<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for MigrateApprovalRoutesToFlowsCommand — the occ entry point people
 * actually run.
 *
 * The command is where a migration's safety rails live: it refuses without an
 * owner, it exits non-zero on a partial run, and `--dry-run` must reach the
 * migrator as a dry run rather than quietly writing. None of that is exercised
 * by the migrator's own tests.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Command
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\MigrateApprovalRoutesToFlowsCommand;
use OCA\Dossiq\Service\Parafeer\EndorsementRouteFlowMigrator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the occ command's contract.
 */
class MigrateApprovalRoutesToFlowsCommandTest extends TestCase {

	/**
	 * Build a tester over the command, with a migrator returning $summary.
	 *
	 * @param array<string, mixed> $summary   What the migrator reports.
	 * @param boolean              $userKnown Whether the named user resolves.
	 * @param array<string, mixed> $seen      Sink recording the migrator's arguments.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(array $summary, bool $userKnown = true, array &$seen = []): CommandTester {
		$migrator = $this->createMock(EndorsementRouteFlowMigrator::class);
		$migrator->method('migrate')->willReturnCallback(
			static function (IUser $user, bool $dryRun) use ($summary, &$seen): array {
				$seen = ['uid' => $user->getUID(), 'dryRun' => $dryRun];

				return $summary;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($userKnown === true ? $user : null);

		return new CommandTester(new MigrateApprovalRoutesToFlowsCommand($migrator, $userManager));

	}//end tester()

	/**
	 * A clean summary.
	 *
	 * @param integer $failed How many rows failed.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function summary(int $failed = 0): array {
		return [
			'total' => 1,
			'created' => 1,
			'updated' => 0,
			'skipped' => 0,
			'failed' => $failed,
			'rows' => [['outcome' => 'created', 'marker' => 'dossiq:endorsementRoute:r-1', 'detail' => 'flow x']],
		];

	}//end summary()

	/**
	 * Without --user the command refuses, because a flow's owner is permanent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-are-flows/specs/approval-routes-are-flows/spec.md
	 */
	public function testItRefusesWithoutAUser(): void {
		$tester = $this->tester($this->summary());

		$this->assertSame(Command::INVALID, $tester->execute([]));
		$this->assertStringContainsString('--user is required', $tester->getDisplay());

	}//end testItRefusesWithoutAUser()

	/**
	 * An unknown uid is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function testItRefusesAnUnknownUser(): void {
		$tester = $this->tester($this->summary(), userKnown: false);

		$this->assertSame(Command::INVALID, $tester->execute(['--user' => 'nobody']));
		$this->assertStringContainsString('No such user', $tester->getDisplay());

	}//end testItRefusesAnUnknownUser()

	/**
	 * A clean run reports its counts and exits zero.
	 *
	 * @return void
	 */
	public function testACleanRunSucceedsAndReportsCounts(): void {
		$tester = $this->tester($this->summary());

		$this->assertSame(Command::SUCCESS, $tester->execute(['--user' => 'admin']));
		$display = $tester->getDisplay();
		$this->assertStringContainsString('created  = 1', $display);
		$this->assertStringContainsString('dossiq:endorsementRoute:r-1', $display);

	}//end testACleanRunSucceedsAndReportsCounts()

	/**
	 * --dry-run reaches the migrator AS a dry run.
	 *
	 * The flag is the whole safety of "look before you write", and a command
	 * that accepted it and passed false would write while saying it had not.
	 *
	 * @return void
	 */
	public function testDryRunReachesTheMigrator(): void {
		$seen = [];
		$tester = $this->tester($this->summary(), seen: $seen);

		$tester->execute(['--user' => 'admin', '--dry-run' => true]);

		$this->assertSame(['uid' => 'admin', 'dryRun' => true], $seen);
		$this->assertStringContainsString('dry run', $tester->getDisplay());

	}//end testDryRunReachesTheMigrator()

	/**
	 * Without --dry-run the migrator is told to write.
	 *
	 * @return void
	 */
	public function testARealRunIsNotADryRun(): void {
		$seen = [];
		$tester = $this->tester($this->summary(), seen: $seen);

		$tester->execute(['--user' => 'admin']);

		$this->assertSame(false, $seen['dryRun']);

	}//end testARealRunIsNotADryRun()

	/**
	 * A failed row exits non-zero.
	 *
	 * Reporting success over a partial migration is how a caller ends up
	 * believing data moved that did not.
	 *
	 * @return void
	 */
	public function testAFailedRowExitsNonZero(): void {
		$tester = $this->tester($this->summary(failed: 1));

		$this->assertSame(Command::FAILURE, $tester->execute(['--user' => 'admin']));

	}//end testAFailedRowExitsNonZero()

	/**
	 * A summary carrying a note prints it and stops.
	 *
	 * @return void
	 */
	public function testANoteIsReportedAndSucceeds(): void {
		$tester = $this->tester(['note' => 'OpenRegister is not available.']);

		$this->assertSame(Command::SUCCESS, $tester->execute(['--user' => 'admin']));
		$this->assertStringContainsString('OpenRegister is not available.', $tester->getDisplay());

	}//end testANoteIsReportedAndSucceeds()

}//end class
