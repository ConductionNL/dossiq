<?php

/**
 * RunsOnceWithObjectService — the once-only opening of a repair step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\RunsOnceWithObjectService;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * A done step does nothing, a missing OpenRegister is reported, otherwise the
 * work runs with the object service.
 *
 * @covers \OCA\Dossiq\Repair\Support\RunsOnceWithObjectService
 * @uses \OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity
 */
final class RunsOnceWithObjectServiceTest extends TestCase {

	/**
	 * A step using the trait, with its opening made public.
	 *
	 * @param string      $done          The stored done marker.
	 * @param object|null $objectService The object service, or null.
	 *
	 * @return object
	 */
	private function subject(string $done, ?object $objectService): object {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($done);
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new class($appConfig, $settings) {
			use RunsUnderSystemIdentity;
			use RunsOnceWithObjectService {
				onceWithObjectService as public;
			}

			public const DONE_KEY = 'step_done';

			public const DONE_VERSION = '2';

			private const UNAVAILABLE_NOTE = 'unavailable';

			/**
			 * Hold the collaborators.
			 *
			 * @param IAppConfig      $appConfig       The app configuration.
			 * @param SettingsService $settingsService The settings.
			 */
			public function __construct(
				private readonly IAppConfig $appConfig,
				private readonly SettingsService $settingsService,
			) {
			}//end __construct()
		};
	}//end subject()

	/**
	 * A step already at its version runs nothing.
	 *
	 * @return void
	 */
	public function testDoneStepRunsNothing(): void {
		$ran = false;
		$this->subject(done: '2', objectService: new \stdClass())->onceWithObjectService(
			output: $this->createMock(originalClassName: IOutput::class),
			work: function () use (&$ran): void {
				$ran = true;
			}
		);

		self::assertFalse(condition: $ran);
	}//end testDoneStepRunsNothing()

	/**
	 * Without OpenRegister the note is printed and nothing runs.
	 *
	 * @return void
	 */
	public function testMissingObjectServiceIsReported(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with('unavailable');
		$ran = false;
		$this->subject(done: '', objectService: null)->onceWithObjectService(
			output: $output,
			work: function () use (&$ran): void {
				$ran = true;
			}
		);

		self::assertFalse(condition: $ran);
	}//end testMissingObjectServiceIsReported()

	/**
	 * Otherwise the work receives the object service.
	 *
	 * @return void
	 */
	public function testWorkReceivesTheObjectService(): void {
		$service = new \stdClass();
		$received = null;
		$this->subject(done: '1', objectService: $service)->onceWithObjectService(
			output: $this->createMock(originalClassName: IOutput::class),
			work: function (object $objectService) use (&$received): void {
				$received = $objectService;
			}
		);

		self::assertSame(expected: $service, actual: $received);
	}//end testWorkReceivesTheObjectService()
}//end class
