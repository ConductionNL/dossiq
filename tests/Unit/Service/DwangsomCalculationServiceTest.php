<?php

/**
 * Unit tests for DwangsomCalculationService.
 *
 * Drives the AWB-default tier transitions (day 14→15, 28→29), plafond
 * enforcement, and beschikking-stop locking.
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

use OCA\Procest\Service\DwangsomCalculationService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\DwangsomCalculationService
 */
class DwangsomCalculationServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private DwangsomCalculationService $service;

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
					'dwangsom_berekening_schema' => 'dwangsomBerekening',
					default => '',
				};
			},
		);

		$this->service = new DwangsomCalculationService($settings, $this->createMock(LoggerInterface::class));
	}

	/**
	 * @return void
	 */
	public function testAwbTierBoundaries(): void {
		self::assertSame(2300, $this->service->dailyTariffAwb(1));
		self::assertSame(2300, $this->service->dailyTariffAwb(14));
		self::assertSame(3500, $this->service->dailyTariffAwb(15));
		self::assertSame(3500, $this->service->dailyTariffAwb(28));
		self::assertSame(4500, $this->service->dailyTariffAwb(29));
		self::assertSame(4500, $this->service->dailyTariffAwb(60));
	}

	/**
	 * @return void
	 */
	public function testCalculateDailyAdvancesOneDayAtTier1(): void {
		$this->objects->saveObject('procest', 'dwangsomBerekening', [
			'id' => 'b1',
			'ingebrekestelling' => 'ig-1',
			'termijnInstance' => 'ti-1',
			'startDatum' => '2026-03-29',
			'huidigeDag' => 0,
			'cumulatievBedrag' => 0,
			'plafondBerekend' => 144200,
			'plafondBereikt' => false,
			'status' => 'lopend',
			'regime' => 'awb-default',
		]);

		$row = $this->service->calculateDaily('b1');
		self::assertSame(1, $row['huidigeDag']);
		self::assertSame(2300, $row['dagtarief']);
		self::assertSame(2300, $row['cumulatievBedrag']);
		self::assertFalse($row['plafondBereikt']);
	}

	/**
	 * @return void
	 */
	public function testCalculateDailyTransitionsToTier2OnDay15(): void {
		$this->objects->saveObject('procest', 'dwangsomBerekening', [
			'id' => 'b2',
			'ingebrekestelling' => 'ig-1',
			'termijnInstance' => 'ti-1',
			'startDatum' => '2026-03-29',
			'huidigeDag' => 14,
			'cumulatievBedrag' => 32200,
			'plafondBerekend' => 144200,
			'plafondBereikt' => false,
			'status' => 'lopend',
			'regime' => 'awb-default',
		]);

		$row = $this->service->calculateDaily('b2');
		self::assertSame(15, $row['huidigeDag']);
		self::assertSame(3500, $row['dagtarief']);
		self::assertSame(35700, $row['cumulatievBedrag']);
	}

	/**
	 * @return void
	 */
	public function testCalculateDailyTransitionsToTier3OnDay29(): void {
		$this->objects->saveObject('procest', 'dwangsomBerekening', [
			'id' => 'b3',
			'ingebrekestelling' => 'ig-1',
			'termijnInstance' => 'ti-1',
			'startDatum' => '2026-03-29',
			'huidigeDag' => 28,
			'cumulatievBedrag' => 81200,
			'plafondBerekend' => 144200,
			'plafondBereikt' => false,
			'status' => 'lopend',
			'regime' => 'awb-default',
		]);

		$row = $this->service->calculateDaily('b3');
		self::assertSame(29, $row['huidigeDag']);
		self::assertSame(4500, $row['dagtarief']);
		self::assertSame(85700, $row['cumulatievBedrag']);
	}

	/**
	 * @return void
	 */
	public function testCalculateDailyCapsAtPlafond(): void {
		$this->objects->saveObject('procest', 'dwangsomBerekening', [
			'id' => 'b4',
			'ingebrekestelling' => 'ig-1',
			'termijnInstance' => 'ti-1',
			'startDatum' => '2026-03-29',
			'huidigeDag' => 41,
			'cumulatievBedrag' => 142000,
			'plafondBerekend' => 144200,
			'plafondBereikt' => false,
			'status' => 'lopend',
			'regime' => 'awb-default',
		]);

		$row = $this->service->calculateDaily('b4');
		self::assertSame(144200, $row['cumulatievBedrag']);
		self::assertTrue($row['plafondBereikt']);

		// Second call after plafond does not change cumulative.
		$row2 = $this->service->calculateDaily('b4');
		self::assertSame(144200, $row2['cumulatievBedrag']);
	}

	/**
	 * @return void
	 */
	public function testStopForBeschikkingLocksDefinitievBedrag(): void {
		$this->objects->saveObject('procest', 'dwangsomBerekening', [
			'id' => 'b5',
			'ingebrekestelling' => 'ig-1',
			'termijnInstance' => 'ti-1',
			'startDatum' => '2026-03-29',
			'huidigeDag' => 5,
			'cumulatievBedrag' => 11500,
			'plafondBerekend' => 144200,
			'plafondBereikt' => false,
			'status' => 'lopend',
		]);

		$stopped = $this->service->stopForBeschikking('b5');
		self::assertSame('gestopt-wegens-beschikking', $stopped['status']);
		self::assertSame(11500, $stopped['definitievBedrag']);

		// Further calculateDaily is a no-op on stopped berekeningen.
		$row = $this->service->calculateDaily('b5');
		self::assertSame(5, $row['huidigeDag']);
	}

	/**
	 * @return void
	 */
	public function testCustomRegimeUsesDefinitionTariff(): void {
		// Seed Woo definition + instance.
		$this->objects->saveObject('procest', 'termijnDefinitie', [
			'id' => 'td-woo',
			'zaaktype' => 'woo-verzoek',
			'afwijkendDwangsomRegime' => ['dailyTariff' => 1500, 'plafond' => 50000, 'grace' => 14],
			'validFrom' => '2026-01-01',
		]);
		$this->objects->saveObject('procest', 'termijnInstance', [
			'id' => 'ti-woo',
			'termijnDefinitie' => 'td-woo',
		]);
		$this->objects->saveObject('procest', 'dwangsomBerekening', [
			'id' => 'b-woo',
			'ingebrekestelling' => 'ig-woo',
			'termijnInstance' => 'ti-woo',
			'startDatum' => '2026-03-29',
			'huidigeDag' => 0,
			'cumulatievBedrag' => 0,
			'plafondBerekend' => 50000,
			'plafondBereikt' => false,
			'status' => 'lopend',
			'regime' => 'afwijkend',
		]);

		$row = $this->service->calculateDaily('b-woo');
		self::assertSame(1500, $row['dagtarief']);
		self::assertSame(1500, $row['cumulatievBedrag']);
	}
}
