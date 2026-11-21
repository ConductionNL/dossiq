<?php

/**
 * InstallShippedPackages imports the register again after it installs a package.
 *
 * 🔴 LIVE DEFECT (Zuiddrecht demo, 14 Oct): the register import runs before
 * this step, so the shipped Woo case type, which names the flow the
 * `woo-verzoek` package writes, was refused on every upgraded instance.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\InstallShippedPackages;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The repair step and the second import.
 */
class InstallShippedPackagesTest extends TestCase {

	/**
	 * The step over an installer answering these outcomes and a settings service answering this import.
	 *
	 * @param array<int, array<string, mixed>> $outcomes The installer's outcomes.
	 * @param array<string, mixed>             $import   What loadConfiguration answers.
	 * @param int                              $imports  How often the import must run.
	 *
	 * @return InstallShippedPackages The step.
	 */
	private function step(array $outcomes, array $import, int $imports): InstallShippedPackages {
		$installer = $this->createMock(PackageInstaller::class);
		$installer->method('reloadInstalled')->willReturn($outcomes);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->expects($this->exactly($imports))->method('loadConfiguration')->with(false)->willReturn($import);

		return new InstallShippedPackages($installer, $settings);
	}//end step()

	/**
	 * An outcome as the installer reports it.
	 *
	 * @param string $status The status.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function outcome(string $status): array {
		return ['status' => $status, 'package' => 'woo-verzoek', 'version' => '1.0.0', 'message' => 'ok'];
	}//end outcome()

	/**
	 * A package installed on this run: the register is imported again, unforced.
	 *
	 * @return void
	 */
	public function testAnInstalledPackageImportsTheRegisterAgain(): void {
		$step = $this->step([$this->outcome(PackageInstaller::INSTALLED)], ['success' => true, 'result' => ['failed' => ['objects' => []]]], 1);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');

		$step->run($output);
	}//end testAnInstalledPackageImportsTheRegisterAgain()

	/**
	 * The upgrade updates every installed package, an on-request one too,
	 * not only the automatic ones.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/repair-updates-installed-packages/specs/case-types/spec.md
	 */
	public function testTheUpgradeUpdatesEveryInstalledPackage(): void {
		$installer = $this->createMock(originalClassName: PackageInstaller::class);
		$installer->expects($this->never())->method('installAutomatic');
		$installer->expects($this->once())->method('reloadInstalled')->willReturn(
			[['status' => PackageInstaller::INSTALLED, 'package' => 'jeugdwet', 'version' => '1.5.0', 'message' => 'ok']]
		);
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('loadConfiguration')->willReturn(['success' => true, 'result' => ['failed' => ['objects' => []]]]);
		$lines = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			}
		);

		(new InstallShippedPackages(installer: $installer, settings: $settings))->run($output);

		$this->assertContains(needle: 'jeugdwet 1.5.0: installed. ok', haystack: $lines);
	}//end testTheUpgradeUpdatesEveryInstalledPackage()

	/**
	 * Every package already current: nothing is imported again.
	 *
	 * @return void
	 */
	public function testCurrentPackagesImportNothing(): void {
		$step = $this->step([$this->outcome(PackageInstaller::CURRENT)], [], 0);

		$step->run($this->createMock(IOutput::class));
	}//end testCurrentPackagesImportNothing()

	/**
	 * A shipped object the second import still could not write is named.
	 *
	 * @return void
	 */
	public function testAnObjectStillRefusedIsNamed(): void {
		$step = $this->step(
			[$this->outcome(PackageInstaller::INSTALLED)],
			['success' => true, 'result' => ['failed' => ['objects' => [['slug' => 'woo-verzoek', 'error' => 'refused']]]]],
			1
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('woo-verzoek'));

		$step->run($output);
	}//end testAnObjectStillRefusedIsNamed()
}//end class
