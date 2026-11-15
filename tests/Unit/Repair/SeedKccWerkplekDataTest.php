<?php

/**
 * What the upgrade reports about the KCC-werkplek seed.
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

use OCA\Dossiq\Repair\SeedKccWerkplekData;
use OCA\Dossiq\Service\KccWerkplekSeedDataService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * No OpenRegister, a complete seed, a partial one and a failing one.
 *
 * @covers \OCA\Dossiq\Repair\SeedKccWerkplekData
 */
final class SeedKccWerkplekDataTest extends TestCase {

	/** @var array<int, array{0: string, 1: string}> */
	private array $out = [];

	/**
	 * An output that records every line with its level.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(originalClassName: IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $line) use ($level): void {
					$this->out[] = [$level, $line];
				}
			);
		}

		return $output;
	}//end recordingOutput()

	/**
	 * Run the step.
	 *
	 * @param bool                         $openRegister Whether OpenRegister is available.
	 * @param array|RuntimeException|null  $result       The seed result or failure; null when seed must not run.
	 * @param LoggerInterface|null         $logger       The logger, a silent one when null.
	 */
	private function seed(bool $openRegister, array|RuntimeException|null $result, ?LoggerInterface $logger = null): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegister);
		$service = $this->createMock(originalClassName: KccWerkplekSeedDataService::class);
		if ($result === null) {
			$service->expects($this->never())->method('seed');
		} elseif ($result instanceof RuntimeException) {
			$service->method('seed')->willThrowException($result);
		} else {
			$service->method('seed')->willReturn($result);
		}

		if ($logger === null) {
			$logger = $this->createMock(originalClassName: LoggerInterface::class);
			$logger->expects($this->never())->method('error');
		}

		(new SeedKccWerkplekData(seedService: $service, settingsService: $settings, logger: $logger))->run($this->recordingOutput());
	}//end seed()

	public function testWithoutOpenRegisterNothingIsSeeded(): void {
		$this->seed(openRegister: false, result: null);

		$this->assertSame([['info', 'Seeding KCC-werkplek defaults...'], ['warning', 'OpenRegister is not available. Skipping KCC-werkplek seed.']], $this->out);
	}//end testWithoutOpenRegisterNothingIsSeeded()

	public function testACompleteSeedIsCounted(): void {
		$this->seed(openRegister: true, result: ['success' => true, 'quickActions' => 6, 'belplannen' => 2, 'skipped' => 1]);

		$this->assertSame(['info', 'KCC-werkplek seed complete: 6 quick-actions, 2 belplannen (1 overgeslagen)'], $this->out[1]);
		$this->assertCount(2, $this->out);
	}//end testACompleteSeedIsCounted()

	public function testAPartialSeedIsAWarningWithEveryCount(): void {
		$this->seed(openRegister: true, result: ['success' => false, 'message' => 'schema missing', 'quickActions' => 1, 'failed' => 3]);
		$this->seed(openRegister: true, result: []);

		$this->assertSame(
			[
				['warning', 'KCC-werkplek seed issue: schema missing (1 quick-actions, 0 belplannen, 0 overgeslagen, 3 geweigerd)'],
				['warning', 'KCC-werkplek seed issue: unknown error (0 quick-actions, 0 belplannen, 0 overgeslagen, 0 geweigerd)'],
			],
			[$this->out[1], $this->out[3]]
		);
	}//end testAPartialSeedIsAWarningWithEveryCount()

	public function testAFailingSeedIsWarnedAndLogged(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Dossiq KCC-werkplek seed failed', ['exception' => 'register locked']);

		$this->seed(openRegister: true, result: new RuntimeException('register locked'), logger: $logger);

		$this->assertSame(['warning', 'Could not seed KCC-werkplek data: register locked'], $this->out[1]);
	}//end testAFailingSeedIsWarnedAndLogged()
}//end class
