<?php

/**
 * The demo caseload seed command: the admin it runs as, the seed and verify-only runs, and the dashboard read-back.
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
use OCA\Dossiq\Command\SeedDemoCaseloadCommand;
use OCA\Dossiq\Service\DemoCaseloadReport;
use OCA\Dossiq\Service\DemoCaseloadSeedDataService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Dossiq\Command\SeedDemoCaseloadCommand
 */
class SeedDemoCaseloadCommandTest extends TestCase {

	/**
	 * The seeder double.
	 *
	 * @var DemoCaseloadSeedDataService&MockObject
	 */
	private DemoCaseloadSeedDataService $seeder;

	/**
	 * The read-back double.
	 *
	 * @var DemoCaseloadReport&MockObject
	 */
	private DemoCaseloadReport $report;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groups;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->seeder  = $this->createMock(DemoCaseloadSeedDataService::class);
		$this->report  = $this->createMock(DemoCaseloadReport::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups  = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * What the dashboard reads back.
	 *
	 * @param int $tasksOpen The open tasks.
	 *
	 * @return array<string, int>
	 */
	private static function buckets(int $tasksOpen=4): array {
		return ['open' => 6, 'overdue' => 2, 'dueSoon' => 1, 'closed' => 3, 'tasksOpen' => $tasksOpen, 'tasksDue' => 2];
	}//end buckets()

	/**
	 * Run the command.
	 *
	 * @param array<string, mixed> $input The command input.
	 *
	 * @return CommandTester The tester after the run.
	 */
	private function runCommand(array $input=[]): CommandTester {
		$tester = new CommandTester(new SeedDemoCaseloadCommand(seeder: $this->seeder, report: $this->report, userSession: $this->session, groupManager: $this->groups));
		$tester->execute($input);

		return $tester;
	}//end runCommand()

	/**
	 * With a signed-in user it seeds, prints the result and the read-back, and succeeds.
	 *
	 * @return void
	 */
	public function testASeedPrintsTheResultAndTheReadBack(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->session->expects($this->never())->method('setUser');
		$this->seeder->expects($this->once())->method('seed')->willReturn(['cases' => 5, 'tasks' => 9, 'skipped' => 1]);
		$this->report->expects($this->once())->method('buckets')->willReturn(self::buckets());

		$tester = $this->runCommand();

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertSame(
			"dossiq:demo:seed done\n  cases created = 5\n  tasks created = 9\n  cases skipped = 1 (already present)\n\n"
			."What the dashboard will read\n  open cases          = 6\n  overdue             = 2\n  deadline within 3d  = 1\n"
			."  closed              = 3\n  open tasks          = 4\n  tasks due within 3d = 2\n",
			$tester->getDisplay()
		);
	}//end testASeedPrintsTheResultAndTheReadBack()

	/**
	 * Verify-only reads back without seeding.
	 *
	 * @return void
	 */
	public function testVerifyOnlyDoesNotSeed(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->seeder->expects($this->never())->method('seed');
		$this->report->method('buckets')->willReturn(self::buckets());

		$tester = $this->runCommand(['--verify-only' => true]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringStartsWith("\nWhat the dashboard will read", $tester->getDisplay());
		self::assertStringNotContainsString('created', $tester->getDisplay());
	}//end testVerifyOnlyDoesNotSeed()

	/**
	 * Without a session it runs as the first admin.
	 *
	 * @return void
	 */
	public function testWithoutASessionItRunsAsTheFirstAdmin(): void {
		$first = $this->createMock(IUser::class);
		$first->method('getUID')->willReturn('beheerder');
		$admins = $this->createMock(IGroup::class);
		$admins->method('getUsers')->willReturn(['beheerder' => $first, 'tweede' => $this->createMock(IUser::class)]);
		$this->groups->expects($this->once())->method('get')->with('admin')->willReturn($admins);
		$this->session->method('getUser')->willReturn(null);
		$this->session->expects($this->once())->method('setUser')->with($first);
		$this->report->method('buckets')->willReturn(self::buckets());

		$tester = $this->runCommand(['--verify-only' => true]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertStringStartsWith("Seeding as admin user \"beheerder\".\n", $tester->getDisplay());
	}//end testWithoutASessionItRunsAsTheFirstAdmin()

	/**
	 * Without a session, an admin group or an admin in it, it refuses before seeding.
	 *
	 * @return void
	 */
	public function testWithoutAnAdminItRefuses(): void {
		$empty = $this->createMock(IGroup::class);
		$empty->method('getUsers')->willReturn([]);
		$this->session->method('getUser')->willReturn(null);
		$this->session->expects($this->never())->method('setUser');
		$this->groups->method('get')->willReturnOnConsecutiveCalls(null, $empty);
		$this->seeder->expects($this->never())->method('seed');
		$this->report->expects($this->never())->method('buckets');

		foreach (['no admin group', 'an empty admin group'] as $case) {
			$tester = $this->runCommand();
			self::assertSame(Command::FAILURE, $tester->getStatusCode(), $case);
			self::assertSame("No admin user found to run the seed under.\n", $tester->getDisplay(), $case);
		}
	}//end testWithoutAnAdminItRefuses()

	/**
	 * A seed that throws is reported with its message, and fails.
	 *
	 * @return void
	 */
	public function testAThrowingSeedIsReported(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->seeder->method('seed')->willThrowException(new RuntimeException('register missing'));
		$this->report->expects($this->never())->method('buckets');

		$tester = $this->runCommand();

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertSame("Demo caseload seed failed: register missing\n", $tester->getDisplay());
	}//end testAThrowingSeedIsReported()

	/**
	 * No open tasks after the seed fails the run with a pointer at OpenRegister.
	 *
	 * @return void
	 */
	public function testNoOpenTasksFailsTheRun(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->report->method('buckets')->willReturn(self::buckets(tasksOpen: 0));

		$tester = $this->runCommand(['--verify-only' => true]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringEndsWith(
			"\nNo open tasks landed. Demo tasks go to OpenRegister's task engine, so check that OpenRegister is enabled.\n",
			$tester->getDisplay()
		);
	}//end testNoOpenTasksFailsTheRun()
}//end class
