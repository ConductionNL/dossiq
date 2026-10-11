<?php

/**
 * MigrateCaseTeamsCommand: occ dossiq:teams:migrate reports what it moved and what it left.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\MigrateCaseTeamsCommand;
use OCA\Dossiq\Service\Team\CaseTeamMigration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Dossiq\Command\MigrateCaseTeamsCommand
 */
class MigrateCaseTeamsCommandTest extends TestCase {

	/**
	 * A report the migration answers with.
	 *
	 * @param array<string, mixed> $overrides The fields that differ.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function report(array $overrides = []): array {
		return array_merge(
			['ran' => true, 'skippedBecause' => '', 'converted' => 0, 'alreadyGroup' => 0, 'failed' => 0, 'unmapped' => []],
			$overrides
		);
	}//end report()

	/**
	 * Run the command over a migration that answers $report.
	 *
	 * @param array<string, mixed> $report        The report.
	 * @param bool                 $expectedApply The apply flag the migration must receive.
	 * @param array<string, mixed> $input         The command input.
	 *
	 * @return CommandTester The tester after the run.
	 */
	private function runCommand(array $report, bool $expectedApply, array $input = []): CommandTester {
		$migration = $this->createMock(CaseTeamMigration::class);
		$migration->expects($this->once())->method('run')->with($expectedApply)->willReturn($report);

		$tester = new CommandTester(new MigrateCaseTeamsCommand(migration: $migration));
		$tester->execute($input);

		return $tester;
	}//end runCommand()

	/**
	 * It applies, and lists every case it left with reason and hint.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function testItAppliesAndListsWhatItLeft(): void {
		$tester = $this->runCommand(
			report: $this->report(
				[
					'converted' => 2,
					'alreadyGroup' => 1,
					'unmapped' => [
						[
							'case' => 'c-1',
							'title' => 'Kapvergunning',
							'value' => 'role-uuid',
							'reason' => CaseTeamMigration::REASON_NO_GROUP,
							'roleName' => 'Team Handhaving',
							'hints' => ['handhaving', 'toezicht'],
						],
					],
				]
			),
			expectedApply: true
		);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		$display = $tester->getDisplay();
		self::assertStringContainsString('Moved 2 cases to their group.', $display);
		self::assertStringContainsString('1 cases already held a group.', $display);
		self::assertStringContainsString('1 cases keep their value.', $display);
		self::assertStringContainsString('Kapvergunning', $display);
		self::assertStringContainsString('role-has-no-group', $display);
		self::assertStringContainsString('handhaving, toezicht', $display);
	}//end testItAppliesAndListsWhatItLeft()

	/**
	 * A dry run does not apply and says what it would move.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function testADryRunDoesNotApply(): void {
		$tester = $this->runCommand(report: $this->report(['converted' => 3]), expectedApply: false, input: ['--dry-run' => true]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('Would move 3 cases to their group.', $tester->getDisplay());
		self::assertStringContainsString('No case was left with an organisation role as its team.', $tester->getDisplay());
	}//end testADryRunDoesNotApply()

	/**
	 * Failed writes are named.
	 *
	 * @return void
	 */
	public function testFailedWritesAreNamed(): void {
		$tester = $this->runCommand(report: $this->report(['failed' => 2]), expectedApply: true);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringContainsString('2 cases could not be written; see the log.', $tester->getDisplay());
	}//end testFailedWritesAreNamed()

	/**
	 * A migration that could not run is a failure, with the reason.
	 *
	 * @return void
	 */
	public function testNothingRanIsAFailure(): void {
		$tester = $this->runCommand(
			report: $this->report(['ran' => false, 'skippedBecause' => 'the case schema is not configured']),
			expectedApply: true
		);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('Nothing ran: the case schema is not configured.', $tester->getDisplay());
	}//end testNothingRanIsAFailure()

	/**
	 * The command carries its occ name.
	 *
	 * @return void
	 */
	public function testItIsNamed(): void {
		$command = new MigrateCaseTeamsCommand(migration: $this->createMock(CaseTeamMigration::class));

		self::assertSame('dossiq:teams:migrate', $command->getName());
		self::assertTrue($command->getDefinition()->hasOption('dry-run'));
	}//end testItIsNamed()
}//end class
