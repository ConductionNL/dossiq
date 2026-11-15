<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RunUpgradeRecordImports;
use OCA\Dossiq\Service\Import\CaseRecordImport;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\RunUpgradeRecordImports
 */
class RunUpgradeRecordImportsTest extends TestCase {
	/**
	 * The step, with the import answering what it is given.
	 *
	 * @param array<int, array<string, mixed>>|RuntimeException $answers What runOnUpgrade answers or throws.
	 * @param bool                                             $openRegister Whether OpenRegister is available.
	 *
	 * @return RunUpgradeRecordImports
	 */
	private function step(array|RuntimeException $answers, bool $openRegister = true): RunUpgradeRecordImports {
		$import = $this->createMock(originalClassName: CaseRecordImport::class);
		if ($answers instanceof RuntimeException) {
			$import->method('runOnUpgrade')->willThrowException($answers);
		} else {
			$import->method('runOnUpgrade')->willReturn($answers);
		}

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegister);

		return new RunUpgradeRecordImports(import: $import, settings: $settings);
	}//end step()

	/**
	 * Each declaration's counts are reported, and each failed record is a warning.
	 *
	 * @return void
	 */
	public function testItReportsTheCountsAndWarnsPerFailure(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects(self::once())->method('info')->with(
			'Record import trajectories (case type type-1): 2 imported, 1 already imported, 1 not moved.'
		);
		$output->expects(self::once())->method('warning')->with('Record r-3 was not moved: The case could not be written.');

		$this->step(
			answers: [[
				'caseType' => 'type-1',
				'key' => 'trajectories',
				'imported' => 2,
				'alreadyImported' => 1,
				'unmigrated' => 1,
				'failed' => [['requestId' => 'r-3', 'reference' => '', 'reason' => 'The case could not be written.']],
			]]
		)->run($output);
	}//end testItReportsTheCountsAndWarnsPerFailure()

	/**
	 * Without OpenRegister, or when the import throws, the upgrade only hears a warning.
	 *
	 * @return void
	 */
	public function testItNeverAbortsTheUpgrade(): void {
		foreach ([[[], false], [new RuntimeException('boom'), true]] as [$answers, $openRegister]) {
			$output = $this->createMock(originalClassName: IOutput::class);
			$output->expects(self::once())->method('warning');
			$this->step(answers: $answers, openRegister: $openRegister)->run($output);
		}
	}//end testItNeverAbortsTheUpgrade()
}//end class
