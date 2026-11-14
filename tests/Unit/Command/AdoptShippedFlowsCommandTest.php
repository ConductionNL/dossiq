<?php

/**
 * The shipped-flow adoption command: the user it requires, what it prints and the exit code it answers.
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

use OCA\Dossiq\Command\AdoptShippedFlowsCommand;
use OCA\Dossiq\Service\Flow\ShippedFlowAdoption;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Dossiq\Command\AdoptShippedFlowsCommand
 */
class AdoptShippedFlowsCommandTest extends TestCase {

	/**
	 * Build a tester over the given collaborators.
	 *
	 * @param ShippedFlowAdoption $adoption    The adoption service.
	 * @param IUserManager        $userManager The user manager.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(ShippedFlowAdoption $adoption, IUserManager $userManager): CommandTester {
		return new CommandTester(new AdoptShippedFlowsCommand(adoption: $adoption, userManager: $userManager));
	}//end tester()

	/**
	 * A user manager that resolves 'admin' to a user.
	 *
	 * @param IUser $user The user to answer.
	 *
	 * @return IUserManager The manager.
	 */
	private function managerFor(IUser $user): IUserManager {
		$manager = $this->createMock(IUserManager::class);
		$manager->expects($this->once())->method('get')->with('admin')->willReturn($user);

		return $manager;
	}//end managerFor()

	/**
	 * The command is registered under its occ name with its three options.
	 *
	 * @return void
	 */
	public function testItIsNamedWithItsOptions(): void {
		$command = new AdoptShippedFlowsCommand(
			adoption: $this->createMock(ShippedFlowAdoption::class),
			userManager: $this->createMock(IUserManager::class)
		);

		self::assertSame('dossiq:flows:adopt', $command->getName());
		$definition = $command->getDefinition();
		self::assertTrue($definition->hasOption('user'));
		self::assertTrue($definition->getOption('user')->isValueRequired());
		self::assertNull($definition->getOption('user')->getDefault());
		self::assertFalse($definition->getOption('enable')->acceptValue());
		self::assertFalse($definition->getOption('dry-run')->acceptValue());
	}//end testItIsNamedWithItsOptions()

	/**
	 * Without --user nothing is looked up or adopted, and the run is invalid.
	 *
	 * @return void
	 */
	public function testAMissingUserIsRefused(): void {
		$adoption = $this->createMock(ShippedFlowAdoption::class);
		$adoption->expects($this->never())->method('adoptAll');
		$manager = $this->createMock(IUserManager::class);
		$manager->expects($this->never())->method('get');

		$tester = $this->tester($adoption, $manager);
		$code   = $tester->execute([]);

		self::assertSame(Command::INVALID, $code);
		self::assertStringContainsString('--user is required', $tester->getDisplay());
	}//end testAMissingUserIsRefused()

	/**
	 * An unknown uid is refused before any adoption.
	 *
	 * @return void
	 */
	public function testAnUnknownUserIsRefused(): void {
		$adoption = $this->createMock(ShippedFlowAdoption::class);
		$adoption->expects($this->never())->method('adoptAll');
		$manager = $this->createMock(IUserManager::class);
		$manager->expects($this->once())->method('get')->with('ghost')->willReturn(null);

		$tester = $this->tester($adoption, $manager);
		$code   = $tester->execute(['--user' => 'ghost']);

		self::assertSame(Command::INVALID, $code);
		self::assertStringContainsString('No such user: ghost', $tester->getDisplay());
	}//end testAnUnknownUserIsRefused()

	/**
	 * A clean run passes the flags through, prints each row and the total, and succeeds.
	 *
	 * @return void
	 */
	public function testACleanRunPrintsEveryRowAndSucceeds(): void {
		$user     = $this->createMock(IUser::class);
		$adoption = $this->createMock(ShippedFlowAdoption::class);
		$adoption->expects($this->once())
			->method('adoptAll')
			->with($user, true, false)
			->willReturn(
				[
					'note' => '',
					'rows' => [
						['name' => 'Intake', 'uuid' => 'u-1', 'outcome' => 'adopted', 'detail' => 'owner admin'],
						['name' => 'Close', 'uuid' => 'u-2', 'outcome' => 'skipped', 'detail' => 'already owned'],
					],
				]
			);

		$tester = $this->tester($adoption, $this->managerFor($user));
		$code   = $tester->execute(['--user' => 'admin', '--enable' => true]);
		$out    = $tester->getDisplay();

		self::assertSame(Command::SUCCESS, $code);
		self::assertStringContainsString('  adopted   Intake — owner admin', $out);
		self::assertStringContainsString('  skipped   Close — already owned', $out);
		self::assertStringContainsString('dossiq:flows:adopt: 2 flow(s), 0 failed.', $out);
		self::assertStringNotContainsString('dry run', $out);
	}//end testACleanRunPrintsEveryRowAndSucceeds()

	/**
	 * A dry run is passed through and announced in the summary line.
	 *
	 * @return void
	 */
	public function testADryRunIsAnnounced(): void {
		$user     = $this->createMock(IUser::class);
		$adoption = $this->createMock(ShippedFlowAdoption::class);
		$adoption->expects($this->once())
			->method('adoptAll')
			->with($user, false, true)
			->willReturn(['note' => '', 'rows' => []]);

		$tester = $this->tester($adoption, $this->managerFor($user));
		$code   = $tester->execute(['--user' => 'admin', '--dry-run' => true]);

		self::assertSame(Command::SUCCESS, $code);
		self::assertStringContainsString(
			'dossiq:flows:adopt (dry run — nothing was written): 0 flow(s), 0 failed.',
			$tester->getDisplay()
		);
	}//end testADryRunIsAnnounced()

	/**
	 * One failed row makes the whole run fail, and is counted.
	 *
	 * @return void
	 */
	public function testAFailedRowFailsTheRun(): void {
		$user     = $this->createMock(IUser::class);
		$adoption = $this->createMock(ShippedFlowAdoption::class);
		$adoption->method('adoptAll')->willReturn(
			[
				'note' => '',
				'rows' => [
					['name' => 'Intake', 'uuid' => 'u-1', 'outcome' => 'adopted', 'detail' => 'ok'],
					['name' => 'Close', 'uuid' => 'u-2', 'outcome' => 'failed', 'detail' => 'boom'],
				],
			]
		);

		$tester = $this->tester($adoption, $this->managerFor($user));
		$code   = $tester->execute(['--user' => 'admin']);

		self::assertSame(Command::FAILURE, $code);
		self::assertStringContainsString('  failed    Close — boom', $tester->getDisplay());
		self::assertStringContainsString('2 flow(s), 1 failed.', $tester->getDisplay());
	}//end testAFailedRowFailsTheRun()

	/**
	 * A note replaces the rows and the run succeeds.
	 *
	 * @return void
	 */
	public function testANoteIsPrintedInsteadOfRows(): void {
		$user     = $this->createMock(IUser::class);
		$adoption = $this->createMock(ShippedFlowAdoption::class);
		$adoption->method('adoptAll')->willReturn(
			[
				'note' => 'OpenRegister has no flow engine.',
				'rows' => [
					['name' => 'Close', 'uuid' => 'u-2', 'outcome' => 'failed', 'detail' => 'ignored'],
				],
			]
		);

		$tester = $this->tester($adoption, $this->managerFor($user));
		$code   = $tester->execute(['--user' => 'admin']);
		$out    = $tester->getDisplay();

		self::assertSame(Command::SUCCESS, $code);
		self::assertStringContainsString('OpenRegister has no flow engine.', $out);
		self::assertStringNotContainsString('Close', $out);
		self::assertStringNotContainsString('flow(s)', $out);
	}//end testANoteIsPrintedInsteadOfRows()
}//end class
