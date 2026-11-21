<?php

/**
 * RequiresObjectService — OpenRegister's object service, or a refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\RequiresObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The object service with and without OpenRegister.
 *
 * @covers \OCA\Dossiq\Service\Support\RequiresObjectService
 */
final class RequiresObjectServiceTest extends TestCase {

	/**
	 * A class using the trait over the given settings service.
	 *
	 * @param SettingsService $settingsService The settings service.
	 *
	 * @return object
	 */
	private function subject(SettingsService $settingsService): object {
		return new class($settingsService) {
			use RequiresObjectService;

			/**
			 * Constructor.
			 *
			 * @param SettingsService $settingsService The settings service.
			 */
			public function __construct(private readonly SettingsService $settingsService) {
			}

			/**
			 * The trait's answer.
			 *
			 * @return object
			 */
			public function objectService(): object {
				return $this->requireObjectService();
			}
		};
	}//end subject()

	/**
	 * The object service is answered when OpenRegister is there.
	 *
	 * @return void
	 */
	public function testAnswersTheObjectService(): void {
		$objectService   = new stdClass();
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getObjectService')->willReturn($objectService);

		self::assertSame($objectService, $this->subject($settingsService)->objectService());
	}//end testAnswersTheObjectService()

	/**
	 * Without OpenRegister the lookup is refused.
	 *
	 * @return void
	 */
	public function testRefusesWithoutOpenRegister(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');
		$this->subject($settingsService)->objectService();
	}//end testRefusesWithoutOpenRegister()
}//end class
