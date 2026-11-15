<?php

/**
 * ReadsDossierRows — a row's uuid and the dossier register lookups.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\Custody\ReadsDossierRows;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * The trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Service\Custody\ReadsDossierRows
 */
final class ReadsDossierRowsTest extends TestCase {

	/**
	 * The uuid is read from id then uuid; the register and schema come from the dossier register.
	 *
	 * @return void
	 */
	public function testLookups(): void {
		$objectService = new \stdClass();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'reg', 'case_schema' => 'case'][$key] ?? '');
		$host = new class(new DossierRegister(settingsService: $settings)) {
			use ReadsDossierRows;

			/**
			 * Constructor.
			 *
			 * @param DossierRegister $dossierRegister The dossier register.
			 */
			public function __construct(private readonly DossierRegister $dossierRegister) {
			}//end __construct()
		};

		self::assertSame('a', $host->uuidOf(row: ['id' => ' a ', 'uuid' => 'b']));
		self::assertSame('b', $host->uuidOf(row: ['uuid' => 'b']));
		self::assertSame('', $host->uuidOf(row: []));
		self::assertSame([$objectService, 'reg'], $host->context());
		self::assertSame('case', $host->schema(key: 'case_schema'));
	}//end testLookups()
}//end class
