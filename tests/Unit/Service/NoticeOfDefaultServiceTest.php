<?php

/**
 * Unit tests for NoticeOfDefaultService.
 *
 * Drives the AWB 4:17 registration through valid + premature + duplicate
 * notices, verifies DwangsomBerekening creation, and asserts the
 * one-dwangsom guard semantics.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Procest\Service\NoticeOfDefaultService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\NoticeOfDefaultService
 *
 * @uses \OCA\Procest\Service\TermijnService
 */
class NoticeOfDefaultServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private TermijnService $termijnService;
	private NoticeOfDefaultService $service;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'procest',
					'termijn_definitie_schema' => 'termijnDefinitie',
					'termijn_instance_schema' => 'termijnInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					'ingebrekestelling_schema' => 'ingebrekestelling',
					'dwangsom_berekening_schema' => 'dwangsomBerekening',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->termijnService = new TermijnService($settings, $logger);
		$this->service = new NoticeOfDefaultService($settings, $this->termijnService, $logger);

		// Seed an AWB-default definition.
		$this->objects->saveObject('procest', 'termijnDefinitie', [
			'id' => 'td-ov',
			'zaaktype' => 'omgevingsvergunning-regulier',
			'wettelijkeGrondslag' => 'Wabo 3.9 lid 1',
			'standaardDuurDagen' => 56,
			'aantalVerlengingen' => 1,
			'validFrom' => '2026-01-01',
		]);

		// Seed an overdue TermijnInstance.
		$this->objects->saveObject('procest', 'termijnInstance', [
			'id' => 'ti-1',
			'zaak' => 'Z/2026/300',
			'termijnDefinitie' => 'td-ov',
			'startDatum' => '2026-01-01T10:00:00+00:00',
			'einddatumBerekend' => '2026-02-25',
			'einddatumActueel' => '2026-02-25',
			'status' => 'overschreden',
			'notificatiesVerstuurd' => [],
		]);
	}

	/**
	 * @return void
	 */
	public function testValidNoticeCreatesBerekeningWithCorrectGrace(): void {
		$row = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-15'),
			'email',
			'doc:1'
		);

		self::assertTrue($row['gevalideerd']);
		self::assertSame('geldig', $row['geldigheidStatus']);
		self::assertArrayHasKey('dwangsomBerekening', $row);

		$b = $row['dwangsomBerekening'];
		self::assertSame('2026-03-29', $b['startDatum']);
		self::assertSame(144200, $b['plafondBerekend']);
		self::assertSame('awb-default', $b['regime']);
		self::assertSame('lopend', $b['status']);

		// Instance has the notice linked.
		$updated = $this->objects->store['termijnInstance']['ti-1'];
		self::assertSame((string)$row['id'], $updated['relevantIngbrekes']);
	}

	/**
	 * @return void
	 */
	public function testPrematureNoticeIsRejected(): void {
		// Use a different instance still in lopend (not overschreden).
		$this->objects->saveObject('procest', 'termijnInstance', [
			'id' => 'ti-lopend',
			'zaak' => 'Z/2026/301',
			'termijnDefinitie' => 'td-ov',
			'startDatum' => '2026-01-01T10:00:00+00:00',
			'einddatumBerekend' => '2026-12-31',
			'einddatumActueel' => '2026-12-31',
			'status' => 'lopend',
			'notificatiesVerstuurd' => [],
		]);

		$row = $this->service->registerNoticeOfDefault(
			'ti-lopend',
			new DateTimeImmutable('2026-03-15'),
			'post'
		);

		self::assertFalse($row['gevalideerd']);
		self::assertSame('premaat', $row['geldigheidStatus']);
		self::assertArrayNotHasKey('dwangsomBerekening', $row);
	}

	/**
	 * @return void
	 */
	public function testSecondNoticeDoesNotSpawnSecondBerekening(): void {
		$first = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-15'),
			'email'
		);
		self::assertArrayHasKey('dwangsomBerekening', $first);

		$second = $this->service->registerNoticeOfDefault(
			'ti-1',
			new DateTimeImmutable('2026-03-20'),
			'post'
		);
		self::assertTrue($second['gevalideerd']);
		self::assertArrayNotHasKey('dwangsomBerekening', $second);

		// Only one berekening in the store.
		self::assertCount(1, $this->objects->store['dwangsomBerekening'] ?? []);
	}

	/**
	 * @return void
	 */
	public function testCustomRegimeIsResolvedFromDefinition(): void {
		$this->objects->saveObject('procest', 'termijnDefinitie', [
			'id' => 'td-woo',
			'zaaktype' => 'woo-verzoek',
			'wettelijkeGrondslag' => 'Woo art 4.4',
			'standaardDuurDagen' => 28,
			'aantalVerlengingen' => 1,
			'afwijkendDwangsomRegime' => ['dailyTariff' => 1500, 'plafond' => 50000, 'grace' => 14],
			'validFrom' => '2026-01-01',
		]);
		$this->objects->saveObject('procest', 'termijnInstance', [
			'id' => 'ti-woo',
			'zaak' => 'Z/2026/302',
			'termijnDefinitie' => 'td-woo',
			'startDatum' => '2026-01-01T10:00:00+00:00',
			'einddatumBerekend' => '2026-01-29',
			'einddatumActueel' => '2026-01-29',
			'status' => 'overschreden',
			'notificatiesVerstuurd' => [],
		]);

		$row = $this->service->registerNoticeOfDefault(
			'ti-woo',
			new DateTimeImmutable('2026-02-15'),
			'post'
		);
		$b = $row['dwangsomBerekening'];
		self::assertSame('afwijkend', $b['regime']);
		self::assertSame(50000, $b['plafondBerekend']);
	}
}
