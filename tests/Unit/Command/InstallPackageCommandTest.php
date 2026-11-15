<?php

/**
 * InstallPackageCommand: occ dossiq:package:install hands --over-local-changes to the installer.
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
 *
 * @spec openspec/changes/package-reinstall-applies-changed-content/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\InstallPackageCommand;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Dossiq\Command\InstallPackageCommand
 */
class InstallPackageCommandTest extends TestCase {

	/**
	 * Run the command with the given input and record how the installer was asked.
	 *
	 * @param array<string, mixed>          $input The command input.
	 * @param array<int, array<int, mixed>> $calls Receives [id, over local changes] per install.
	 *
	 * @return integer The exit code.
	 */
	private function runCommand(array $input, array &$calls): int {
		$installer = $this->createMock(originalClassName: PackageInstaller::class);
		$answer = static fn (string $id): array => ['status' => PackageInstaller::INSTALLED, 'package' => $id, 'version' => '1.4.0', 'message' => 'ok'];
		$installer->method('install')->willReturnCallback(
			static function (string $id) use (&$calls, $answer): array {
				$calls[] = [$id, false];

				return $answer($id);
			}
		);
		$installer->method('installOverLocalChanges')->willReturnCallback(
			static function (string $id) use (&$calls, $answer): array {
				$calls[] = [$id, true];

				return $answer($id);
			}
		);
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$tester = new CommandTester(command: new InstallPackageCommand(installer: $installer, settings: $settings));

		return $tester->execute(input: $input);
	}//end runCommand()

	/**
	 * The option reaches the installer for every named package.
	 *
	 * @return void
	 */
	public function testOverLocalChangesReachesTheInstaller(): void {
		$calls = [];

		$exit = $this->runCommand(input: ['ids' => ['jeugdwet'], '--over-local-changes' => true], calls: $calls);

		$this->assertSame(expected: Command::SUCCESS, actual: $exit);
		$this->assertSame(expected: [['jeugdwet', true]], actual: $calls);
	}//end testOverLocalChangesReachesTheInstaller()

	/**
	 * Without the option an edit here is never taken over.
	 *
	 * @return void
	 */
	public function testWithoutTheOptionLocalChangesAreKept(): void {
		$calls = [];

		$this->runCommand(input: ['ids' => ['jeugdwet']], calls: $calls);

		$this->assertSame(expected: [['jeugdwet', false]], actual: $calls);
	}//end testWithoutTheOptionLocalChangesAreKept()

	/**
	 * The output says what the refresh did with the rows already here, not only
	 * what the writer wrote: "0 written, 33 already present" read as a no-op
	 * while the refresh had updated the case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-package-install-reports-what-its-refresh-did
	 */
	public function testTheOutputNamesTheRefreshedAndKeptRows(): void {
		$installer = $this->createMock(originalClassName: PackageInstaller::class);
		$installer->method('installOverLocalChanges')->willReturn(
			['status' => PackageInstaller::INSTALLED, 'package' => 'jeugdwet', 'version' => '1.13.0', 'message' => 'ok', 'refreshed' => 2, 'kept' => ['c-1']]
		);
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$tester = new CommandTester(command: new InstallPackageCommand(installer: $installer, settings: $settings));

		$tester->execute(input: ['ids' => ['jeugdwet'], '--over-local-changes' => true]);

		$this->assertStringContainsString(needle: '2 row(s) refreshed, 1 kept as this instance holds them: c-1', haystack: $tester->getDisplay());
	}//end testTheOutputNamesTheRefreshedAndKeptRows()

	/**
	 * The catalogue lists every package with its version, install mode, installed version and validity.
	 *
	 * @return void
	 */
	public function testTheListNamesEveryPackage(): void {
		$installer = $this->createMock(originalClassName: PackageInstaller::class);
		$installer->method('catalogue')->willReturn(
			[
				['id' => 'jeugdwet', 'version' => '1.13.0', 'install' => 'auto', 'installed' => '1.12.0', 'valid' => true],
				['id' => 'wmo', 'version' => '2.0.0', 'install' => 'manual', 'installed' => '', 'valid' => false],
			]
		);
		$installer->expects($this->never())->method('install');
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$tester = new CommandTester(command: new InstallPackageCommand(installer: $installer, settings: $settings));

		$exit = $tester->execute(input: ['--list' => true]);

		$this->assertSame(expected: Command::SUCCESS, actual: $exit);
		$this->assertSame(
			expected: "jeugdwet\t1.13.0\tauto\tinstalled 1.12.0\nwmo\t2.0.0\tmanual\tinstalled - INVALID\n",
			actual: $tester->getDisplay()
		);
	}//end testTheListNamesEveryPackage()

	/**
	 * Without a package id, --all or --list the command fails and says what to pass.
	 *
	 * @return void
	 */
	public function testWithoutAPackageTheCommandFails(): void {
		$calls = [];

		$exit = $this->runCommand(input: [], calls: $calls);

		$this->assertSame(expected: Command::FAILURE, actual: $exit);
		$this->assertSame(expected: [], actual: $calls);
	}//end testWithoutAPackageTheCommandFails()

	/**
	 * --all installs the whole catalogue, and a package that did not install fails the run after the rest went on.
	 *
	 * @return void
	 */
	public function testAllInstallsTheCatalogueAndAFailedPackageFailsTheRun(): void {
		$installer = $this->createMock(originalClassName: PackageInstaller::class);
		$installer->method('catalogue')->willReturn([['id' => 'a'], ['id' => 'b'], ['id' => 'c']]);
		$installer->method('install')->willReturnCallback(
			static fn (string $id): array => [
				'status' => ['a' => PackageInstaller::CURRENT, 'b' => 'refused', 'c' => PackageInstaller::INSTALLED][$id],
				'package' => $id,
				'version' => '1.0.0',
				'message' => 'm-' . $id,
				'errors' => ($id === 'b') ? ['field x is missing'] : [],
			]
		);
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$tester = new CommandTester(command: new InstallPackageCommand(installer: $installer, settings: $settings));

		$exit = $tester->execute(input: ['--all' => true]);

		$this->assertSame(expected: Command::FAILURE, actual: $exit);
		$this->assertSame(
			expected: "a 1.0.0: " . PackageInstaller::CURRENT . ". m-a\nb 1.0.0: refused. m-b\n  field x is missing\nc 1.0.0: " . PackageInstaller::INSTALLED . ". m-c\n",
			actual: $tester->getDisplay()
		);
	}//end testAllInstallsTheCatalogueAndAFailedPackageFailsTheRun()
}//end class
