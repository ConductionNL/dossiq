<?php

/**
 * DelegatesToDossierRegister — context and schemas through the dossier register.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\DelegatesToDossierRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The register's answers, and its refusals, pass through unchanged.
 *
 * @covers \OCA\Dossiq\Service\Support\DelegatesToDossierRegister
 */
final class DelegatesToDossierRegisterTest extends TestCase {

	/**
	 * A class using the trait over a register built on the given settings.
	 *
	 * @param SettingsService $settings The settings.
	 *
	 * @return object
	 */
	private function subject(SettingsService $settings): object {
		return new class(new DossierRegister(settingsService: $settings)) {
			use DelegatesToDossierRegister {
				context as public;
				schema as public;
			}

			/**
			 * Constructor.
			 *
			 * @param DossierRegister $dossierRegister The register.
			 */
			public function __construct(private readonly DossierRegister $dossierRegister) {
			}
		};
	}//end subject()

	/**
	 * A configured register answers its context and schemas.
	 *
	 * @return void
	 */
	public function testAnswersTheRegister(): void {
		$objectService = new stdClass();
		$settings      = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', 'reg'], ['case_schema', '', 'case']]);

		$subject = $this->subject($settings);
		self::assertSame([$objectService, 'reg'], $subject->context());
		self::assertSame('case', $subject->schema('case_schema'));
	}//end testAnswersTheRegister()

	/**
	 * Without OpenRegister the register refuses, and so does the trait.
	 *
	 * @return void
	 */
	public function testPassesTheRefusalOn(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->subject($settings)->context();
	}//end testPassesTheRefusalOn()
}//end class
