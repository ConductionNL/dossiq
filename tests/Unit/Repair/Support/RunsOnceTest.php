<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Repair\Support\RepairSchema;
use OCA\Dossiq\Repair\Support\RunsOnce;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Done steps skip, missing OpenRegister is reported, otherwise the work runs once.
 *
 * @covers \OCA\Dossiq\Repair\Support\RunsOnce
 */
class RunsOnceTest extends TestCase {

	/**
	 * A step over the given stored marker and settings.
	 *
	 * @param string                   $marker   The stored done marker.
	 * @param SettingsServiceInterface $settings The settings.
	 *
	 * @return object
	 */
	private function step(string $marker, SettingsServiceInterface $settings): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($marker);

		return new class ($appConfig, $settings) {
			use RunsUnderSystemIdentity;
			use RunsOnce;

			public const DONE_KEY = 'test_done';
			public const DONE_VERSION = '2';
			private const TARGET_SCHEMA_KEY = 'case_schema';
			private const UNAVAILABLE_NOTE = 'unavailable';

			public function __construct(
				private readonly IAppConfig $appConfig,
				private readonly SettingsServiceInterface $settingsService,
			) {
			}

			public function schema(IOutput $output, callable $work): void {
				$this->onceOnSchema(output: $output, work: $work);
			}
		};
	}

	public function testDoneStepDoesNothing(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->expects($this->never())->method('getObjectService');
		$step = $this->step(marker: '2', settings: $settings);

		$step->schema($this->createMock(IOutput::class), fn () => $this->fail('ran'));
	}

	public function testMissingOpenRegisterIsReported(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn(null);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('unavailable');
		$step = $this->step(marker: '1', settings: $settings);

		$step->schema($output, fn () => $this->fail('ran'));
	}

	public function testWorkRunsOnTheTarget(): void {
		$objectService = new stdClass();
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', 'reg'], ['case_schema', '', 'case']]);
		$step = $this->step(marker: '', settings: $settings);

		$seen = [];
		$step->schema($this->createMock(IOutput::class), function (RepairSchema $target) use (&$seen): void {
			$seen[] = $target->register . '/' . $target->schema;
		});

		$this->assertSame(['reg/case'], $seen);
	}
}
