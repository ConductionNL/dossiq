<?php

/**
 * The three migrate commands, characterised.
 *
 * `dossiq:actions:migrate-to-flows`, `dossiq:workflows:migrate-to-flows` and
 * `dossiq:lhs:migrate-to-decision-tables` share their options, their acting
 * user checks and their report. This pins each one's name, options, refusals,
 * exact console output and exit code.
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

use OCA\Dossiq\Command\MigrateAutomaticActionsToFlowsCommand;
use OCA\Dossiq\Command\MigrateLhsToDecisionTablesCommand;
use OCA\Dossiq\Command\MigrateWorkflowDefinitionsToFlowsCommand;
use OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator;
use OCA\Dossiq\Service\Vth\LhsMatrixDecisionTableMigrator;
use OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Options, refusals, report and exit code of each migrate command.
 *
 * @covers \OCA\Dossiq\Command\MigrateAutomaticActionsToFlowsCommand
 * @covers \OCA\Dossiq\Command\MigrateWorkflowDefinitionsToFlowsCommand
 * @covers \OCA\Dossiq\Command\MigrateLhsToDecisionTablesCommand
 */
class MigrateToFlowsCommandsTest extends TestCase {

	/**
	 * The commands: class, migrator class, name, dry-run note, row separator.
	 *
	 * @return array<string, array{0: class-string, 1: class-string, 2: string, 3: string, 4: string}>
	 */
	public static function commands(): array {
		return [
			'actions' => [MigrateAutomaticActionsToFlowsCommand::class, AutomaticActionFlowMigrator::class, 'dossiq:actions:migrate-to-flows', ' (dry run — nothing was written)', ' — '],
			'workflows' => [MigrateWorkflowDefinitionsToFlowsCommand::class, WorkflowTemplateFlowMigrator::class, 'dossiq:workflows:migrate-to-flows', ' (dry run — nothing was written)', ' — '],
			'lhs' => [MigrateLhsToDecisionTablesCommand::class, LhsMatrixDecisionTableMigrator::class, 'dossiq:lhs:migrate-to-decision-tables', ' (dry run, nothing was written)', ': '],
		];
	}//end commands()

	/**
	 * Build one command over a migrator that answers this summary.
	 *
	 * @param string                    $class         The command class.
	 * @param string                    $migratorClass The migrator class.
	 * @param array<string, mixed>|null $summary       What migrate() answers; null when it must not run.
	 * @param array<int, mixed>         $calls         The migrate() calls, as [uid, dryRun].
	 *
	 * @return Command
	 */
	private function command(string $class, string $migratorClass, ?array $summary, array &$calls): Command {
		$migrator = $this->createMock($migratorClass);
		if ($summary === null) {
			$migrator->expects($this->never())->method('migrate');
		} else {
			$migrator->method('migrate')->willReturnCallback(
				function (IUser $user, bool $dryRun) use ($summary, &$calls): array {
					$calls[] = [$user->getUID(), $dryRun];
					return $summary;
				}
			);
		}

		$anna = $this->createMock(IUser::class);
		$anna->method('getUID')->willReturn('anna');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'anna' ? $anna : null));

		return new $class($migrator, $users);
	}//end command()

	/**
	 * The summary of a run with one failed row.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(int $failed): array {
		return [
			'total' => 3,
			'created' => 1,
			'updated' => 1,
			'skipped' => 0,
			'failed' => $failed,
			'rows' => [
				['outcome' => 'created', 'marker' => 'a-1', 'detail' => 'new flow'],
				['outcome' => 'failed', 'marker' => 'a-2', 'detail' => 'no step'],
			],
		];
	}//end summary()

	/**
	 * The name and the two options.
	 *
	 * @param string $class         The command class.
	 * @param string $migratorClass The migrator class.
	 * @param string $name          The command name.
	 *
	 * @dataProvider commands
	 *
	 * @return void
	 */
	public function testTheNameAndOptions(string $class, string $migratorClass, string $name): void {
		$calls = [];
		$command = $this->command($class, $migratorClass, null, $calls);
		$definition = $command->getDefinition();

		$this->assertSame($name, $command->getName());
		$this->assertNotSame('', $command->getDescription());
		$this->assertSame(['user', 'dry-run'], array_keys($definition->getOptions()));
		$this->assertTrue($definition->getOption('user')->isValueRequired());
		$this->assertSame('UID the created flows belong to; also supplies the active organisation.', $definition->getOption('user')->getDescription());
		$this->assertFalse($definition->getOption('dry-run')->acceptValue());
		$this->assertSame('Report what would be created or updated, and write nothing.', $definition->getOption('dry-run')->getDescription());
	}//end testTheNameAndOptions()

	/**
	 * Without a user, or with an unknown one, nothing is migrated.
	 *
	 * @param string $class         The command class.
	 * @param string $migratorClass The migrator class.
	 *
	 * @dataProvider commands
	 *
	 * @return void
	 */
	public function testAUserIsRequired(string $class, string $migratorClass): void {
		$calls = [];
		$tester = new CommandTester($this->command($class, $migratorClass, null, $calls));

		$this->assertSame(Command::INVALID, $tester->execute([]));
		$this->assertSame("--user is required: a flow needs an owner and an organisation.\n", $tester->getDisplay());

		$this->assertSame(Command::INVALID, $tester->execute(['--user' => 'nobody']));
		$this->assertSame("No such user: nobody\n", $tester->getDisplay());
	}//end testAUserIsRequired()

	/**
	 * The report, and a failed row exits non-zero.
	 *
	 * @param string $class         The command class.
	 * @param string $migratorClass The migrator class.
	 * @param string $name          The command name.
	 * @param string $dryRunNote    The dry-run note.
	 * @param string $separator     The row separator.
	 *
	 * @dataProvider commands
	 *
	 * @return void
	 */
	public function testTheReportAndTheExitCode(string $class, string $migratorClass, string $name, string $dryRunNote, string $separator): void {
		$calls = [];
		$tester = new CommandTester($this->command($class, $migratorClass, $this->summary(1), $calls));

		$this->assertSame(Command::FAILURE, $tester->execute(['--user' => 'anna', '--dry-run' => true]));
		$this->assertSame(
			$name . $dryRunNote . "\n"
			. "  total    = 3\n"
			. "  created  = 1\n"
			. "  updated  = 1\n"
			. "  skipped  = 0\n"
			. "  failed   = 1\n"
			. '  [created] a-1' . $separator . "new flow\n"
			. '  [failed] a-2' . $separator . "no step\n",
			$tester->getDisplay()
		);
		$this->assertSame([['anna', true]], $calls);

		$tester = new CommandTester($this->command($class, $migratorClass, $this->summary(0), $calls));
		$this->assertSame(Command::SUCCESS, $tester->execute(['--user' => 'anna']));
		$this->assertStringStartsWith($name . "\n", $tester->getDisplay());
		$this->assertSame(['anna', false], $calls[1]);
	}//end testTheReportAndTheExitCode()

	/**
	 * A note from the migrator is all that is printed, and is a success.
	 *
	 * @param string $class         The command class.
	 * @param string $migratorClass The migrator class.
	 *
	 * @dataProvider commands
	 *
	 * @return void
	 */
	public function testANoteIsASuccess(string $class, string $migratorClass): void {
		$calls = [];
		$summary = $this->summary(1);
		$summary['note'] = 'OpenRegister flows are not available.';
		$tester = new CommandTester($this->command($class, $migratorClass, $summary, $calls));

		$this->assertSame(Command::SUCCESS, $tester->execute(['--user' => 'anna']));
		$this->assertSame("OpenRegister flows are not available.\n", $tester->getDisplay());

		$summary['note'] = '';
		$tester = new CommandTester($this->command($class, $migratorClass, $summary, $calls));
		$this->assertSame(Command::FAILURE, $tester->execute(['--user' => 'anna']));
	}//end testANoteIsASuccess()
}//end class
