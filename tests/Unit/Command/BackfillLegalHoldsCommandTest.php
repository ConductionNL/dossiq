<?php

/**
 * The legal hold backfill command: the admin it runs as, and its refusal without OpenRegister.
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
use OCA\Dossiq\Command\Backfill\AwbProceedingScanner;
use OCA\Dossiq\Command\Backfill\LegalHoldApplier;
use OCA\Dossiq\Command\BackfillLegalHoldsCommand;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * OpenRegister is not installed in the unit environment, so every run here
 * reaches the refusal; the scan and the applier have golden tests of their own.
 *
 * @covers \OCA\Dossiq\Command\BackfillLegalHoldsCommand
 */
class BackfillLegalHoldsCommandTest extends TestCase {

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
	 * The scanner double.
	 *
	 * @var AwbProceedingScanner&MockObject
	 */
	private AwbProceedingScanner $scanner;

	/**
	 * The applier double.
	 *
	 * @var LegalHoldApplier&MockObject
	 */
	private LegalHoldApplier $applier;

	/**
	 * Fresh doubles per test; the scan and the applier must never run without OpenRegister.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->session = $this->createMock(IUserSession::class);
		$this->groups  = $this->createMock(IGroupManager::class);
		$this->scanner = $this->createMock(AwbProceedingScanner::class);
		$this->applier = $this->createMock(LegalHoldApplier::class);
		$this->scanner->expects($this->never())->method('scan');
		$this->applier->expects($this->never())->method('reportAndApply');
	}//end setUp()

	/**
	 * Run the command.
	 *
	 * @param array<string, mixed> $input The command input.
	 *
	 * @return CommandTester The tester after the run.
	 */
	private function runCommand(array $input=[]): CommandTester {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$tester = new CommandTester(
			new BackfillLegalHoldsCommand(
				container: $container,
				userSession: $this->session,
				groupManager: $this->groups,
				scanner: $this->scanner,
				applier: $this->applier
			)
		);
		$tester->execute($input);

		return $tester;
	}//end runCommand()

	/**
	 * The command is named and takes --apply and --include-deleted as flags.
	 *
	 * @return void
	 */
	public function testItIsNamedWithItsFlags(): void {
		$command = new BackfillLegalHoldsCommand(
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->session,
			groupManager: $this->groups,
			scanner: $this->scanner,
			applier: $this->applier
		);

		self::assertSame('dossiq:legal-hold:backfill', $command->getName());
		foreach (['apply', 'include-deleted'] as $flag) {
			self::assertFalse($command->getDefinition()->getOption($flag)->acceptValue(), $flag);
		}
	}//end testItIsNamedWithItsFlags()

	/**
	 * A signed-in user runs as themselves, and the run stops on the missing OpenRegister.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItRefuses(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->session->expects($this->never())->method('setUser');
		$this->groups->expects($this->never())->method('get');

		$tester = $this->runCommand(['--apply' => true, '--include-deleted' => true]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertSame("OpenRegister is not available; cannot backfill legal holds.\n", $tester->getDisplay());
	}//end testWithoutOpenRegisterItRefuses()

	/**
	 * Without a session it runs as the first admin.
	 *
	 * @return void
	 */
	public function testWithoutASessionItRunsAsTheFirstAdmin(): void {
		$first = $this->createMock(IUser::class);
		$first->method('getUID')->willReturn('beheerder');
		$admins = $this->createMock(IGroup::class);
		$admins->method('getUsers')->willReturn([$first, $this->createMock(IUser::class)]);
		$this->session->method('getUser')->willReturn(null);
		$this->session->expects($this->once())->method('setUser')->with($first);
		$this->groups->expects($this->once())->method('get')->with('admin')->willReturn($admins);

		$tester = $this->runCommand();

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertSame(
			"Running as admin user \"beheerder\".\nOpenRegister is not available; cannot backfill legal holds.\n",
			$tester->getDisplay()
		);
	}//end testWithoutASessionItRunsAsTheFirstAdmin()

	/**
	 * Without an admin group, or with an empty one, it refuses with the matching message.
	 *
	 * @return void
	 */
	public function testWithoutAnAdminItRefuses(): void {
		$empty = $this->createMock(IGroup::class);
		$empty->method('getUsers')->willReturn([]);
		$this->session->method('getUser')->willReturn(null);
		$this->session->expects($this->never())->method('setUser');
		$this->groups->method('get')->willReturnOnConsecutiveCalls(null, $empty);

		$noGroup = $this->runCommand();
		self::assertSame(Command::FAILURE, $noGroup->getStatusCode());
		self::assertSame("No admin group found to run the backfill under.\n", $noGroup->getDisplay());

		$noUser = $this->runCommand();
		self::assertSame(Command::FAILURE, $noUser->getStatusCode());
		self::assertSame("No admin user found to run the backfill under.\n", $noUser->getDisplay());
	}//end testWithoutAnAdminItRefuses()
}//end class
