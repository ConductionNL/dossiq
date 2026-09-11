<?php

/**
 * StUF confidentiality maps onto the case schema's vocabulary.
 *
 * The case schema stores confidentiality as the ZGW vertrouwelijkheidaanduiding
 * enum, in Dutch, which is statutory (dossiq#1841). StufFieldMappingService
 * mapped an inbound StUF level to English (`public`, `case_sensitive`), which
 * that enum refuses, and mapped a stored Dutch level back to StUF by
 * upper-casing it, which spells `BEPERKT_OPENBAAR` where StUF says
 * `BEPERKT OPENBAAR`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\StufFieldMappingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Round trip between the StUF levels and the stored levels.
 *
 * @covers \OCA\Dossiq\Service\StufFieldMappingService
 */
class StufFieldMappingServiceConfidentialityTest extends TestCase {

	/**
	 * The StUF spelling of each stored level.
	 *
	 * @var array<string, string>
	 */
	private const STUF_BY_LEVEL = [
		'openbaar' => 'OPENBAAR',
		'beperkt_openbaar' => 'BEPERKT OPENBAAR',
		'intern' => 'INTERN',
		'zaakvertrouwelijk' => 'ZAAKVERTROUWELIJK',
		'vertrouwelijk' => 'VERTROUWELIJK',
		'confidentieel' => 'CONFIDENTIEEL',
		'geheim' => 'GEHEIM',
		'zeer_geheim' => 'ZEER GEHEIM',
	];

	/**
	 * The mapping covers exactly the levels the case schema declares.
	 *
	 * @return void
	 */
	public function testTheMappingCoversTheCaseSchemaEnum(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		$this->assertSame(
			$register['components']['schemas']['case']['properties']['confidentiality']['enum'],
			array_keys(self::STUF_BY_LEVEL)
		);

	}//end testTheMappingCoversTheCaseSchemaEnum()

	/**
	 * An inbound StUF level becomes the stored level the schema accepts.
	 *
	 * @return void
	 */
	public function testAnInboundLevelMapsToTheStoredLevel(): void {
		$service = new StufFieldMappingService(logger: $this->createMock(LoggerInterface::class));

		foreach (self::STUF_BY_LEVEL as $level => $stuf) {
			$this->assertSame($level, $service->confidentialityToInternal(stufValue: $stuf), $stuf);
		}

		$mapped = $service->mapZknToInternal(['vertrouwelijkAanduiding' => 'ZAAKVERTROUWELIJK']);
		$this->assertSame('zaakvertrouwelijk', $mapped['confidentiality']);

	}//end testAnInboundLevelMapsToTheStoredLevel()

	/**
	 * A stored level goes out in the StUF spelling, spaces included.
	 *
	 * @return void
	 */
	public function testAStoredLevelMapsToTheStufSpelling(): void {
		$service = new StufFieldMappingService(logger: $this->createMock(LoggerInterface::class));

		foreach (self::STUF_BY_LEVEL as $level => $stuf) {
			$this->assertSame($stuf, $service->confidentialityToStuf(internalValue: $level), $level);
		}

	}//end testAStoredLevelMapsToTheStufSpelling()
}//end class
