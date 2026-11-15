<?php

/**
 * What the upgrade reports about the intake-channel seed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\SeedIntakeSources;
use OCA\Dossiq\Service\Intake\IntakeSourceSeeder;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * No OpenRegister, no register yet, refusals and a clean seed.
 *
 * @covers \OCA\Dossiq\Repair\SeedIntakeSources
 */
final class SeedIntakeSourcesTest extends TestCase {
	/** @var array<int, array{0: string, 1: string}> */
	private array $out = [];

	/**
	 * Run the step.
	 *
	 * @param bool       $openRegister Whether OpenRegister is available.
	 * @param array|null $result       What the seeder answers; null when it must not run.
	 * @param array|null $logged       Expected logger warning context, or null for none.
	 */
	private function seed(bool $openRegister, ?array $result, ?array $logged = null): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegister);
		$seeder = $this->createMock(originalClassName: IntakeSourceSeeder::class);
		if ($result === null) {
			$seeder->expects($this->never())->method('seed');
		} else {
			$seeder->expects($this->once())->method('seed')->willReturn($result);
		}

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		if ($logged === null) {
			$logger->expects($this->never())->method('warning');
		} else {
			$logger->expects($this->once())->method('warning')->with('Dossiq: intake-source seed refused rows', $logged);
		}

		$output = $this->createMock(originalClassName: IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $line) use ($level): void {
					$this->out[] = [$level, $line];
				}
			);
		}

		(new SeedIntakeSources(settingsService: $settings, seeder: $seeder, logger: $logger))->run($output);
	}//end seed()

	public function testWithoutOpenRegisterNothingIsSeeded(): void {
		$this->seed(openRegister: false, result: null);

		$this->assertSame([['warning', 'OpenRegister is not installed or enabled. Skipping the intake-source seed.']], $this->out);
	}//end testWithoutOpenRegisterNothingIsSeeded()

	public function testAMissingRegisterIsReportedForTheNextUpgrade(): void {
		$this->seed(openRegister: true, result: ['available' => false, 'created' => 0, 'updated' => 0, 'refused' => []]);

		$this->assertCount(1, $this->out);
		$this->assertSame('warning', $this->out[0][0]);
		$this->assertStringStartsWith('The OpenRegister intake-sources register is not present yet; no intake channel was seeded.', $this->out[0][1]);
	}//end testAMissingRegisterIsReportedForTheNextUpgrade()

	public function testRefusalsAreNamedAndLogged(): void {
		$this->seed(
			openRegister: true,
			result: ['available' => true, 'created' => 2, 'updated' => 1, 'refused' => ['email', 'portal']],
			logged: ['refused' => ['email', 'portal']]
		);

		$this->assertSame([['warning', 'Intake channels: 2 created, 1 refreshed, 2 REFUSED (email, portal). See the log for each refusal.']], $this->out);
	}//end testRefusalsAreNamedAndLogged()

	public function testACleanSeedIsCounted(): void {
		$this->seed(openRegister: true, result: ['available' => true, 'created' => 3, 'updated' => 4, 'refused' => []]);

		$this->assertSame([['info', 'Intake channels seeded: 3 created, 4 refreshed.']], $this->out);
	}//end testACleanSeedIsCounted()
}//end class
