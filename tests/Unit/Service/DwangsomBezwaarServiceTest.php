<?php

/**
 * Unit tests for DwangsomBezwaarService.
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

use OCA\Procest\Service\DwangsomBezwaarService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\DwangsomBezwaarService
 *
 * @uses \OCA\Procest\Service\TermijnService
 */
class DwangsomBezwaarServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private DwangsomBezwaarService $service;

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
					'dwangsom_berekening_schema' => 'dwangsomBerekening',
					'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new DwangsomBezwaarService(
			$settings,
			new TermijnService($settings, $logger),
			$logger
		);

		// Seed berekening + uitbetaling.
		$this->objects->saveObject('procest', 'dwangsomBerekening', [
			'id' => 'b-1',
			'termijnInstance' => 'ti-1',
			'status' => 'gestopt-wegens-beschikking',
			'definitiveAmount' => 50000,
		]);
		$this->objects->saveObject('procest', 'dwangsomUitbetaling', [
			'id' => 'u-1',
			'dwangsomBerekening' => 'b-1',
			'amount' => 50000,
			'status' => 'voorbereid',
		]);
	}

	/**
	 * @return void
	 */
	public function testRegisterBezwaarFreezesBerekeningAndHoldsUitbetaling(): void {
		$b = $this->service->registerBezwaar('b-1', 'AWB 7:1', 'Belanghebbende betwist bedrag');
		self::assertSame('bezwaar-bevroren', $b['status']);

		$u = $this->objects->store['dwangsomUitbetaling']['u-1'];
		self::assertSame('on-hold-bezwaar', $u['status']);

		// bezwaar-ingediend event recorded.
		$events = array_values($this->objects->store['termijnGebeurtenis'] ?? []);
		self::assertNotEmpty($events);
		self::assertSame('bezwaar-ingediend', $events[0]['type']);
	}

	/**
	 * @return void
	 */
	public function testResolveBezwaarAdjustsAmountAndResumes(): void {
		$this->service->registerBezwaar('b-1', 'AWB 7:1', 'foo');
		$b = $this->service->resolveBezwaar('b-1', 30000, 'AWB 7:11');

		self::assertSame(30000, $b['definitiveAmount']);
		self::assertSame('voltooid', $b['status']);

		$u = $this->objects->store['dwangsomUitbetaling']['u-1'];
		self::assertSame(30000, $u['amount']);
		self::assertSame('voorbereid', $u['status']);
	}

	/**
	 * @return void
	 */
	public function testResolveBezwaarRejectsNegativeAmount(): void {
		$this->expectException(RuntimeException::class);
		$this->service->resolveBezwaar('b-1', -1, 'AWB 7:11');
	}

	/**
	 * @return void
	 */
	public function testRegisterBezwaarOnUnknownBerekeningFails(): void {
		$this->expectException(RuntimeException::class);
		$this->service->registerBezwaar('does-not-exist', 'AWB 7:1', 'foo');
	}
}
