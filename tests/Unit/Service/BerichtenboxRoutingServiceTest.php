<?php

/**
 * BerichtenboxRoutingService Unit Tests.
 *
 * Verifies channel resolution: burger -> MijnOverheid, bedrijf -> eHerkenning,
 * and the print-post fallback when the addressee has not activated a digital
 * channel.
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

use OCA\Procest\Service\BerichtenboxRoutingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BerichtenboxRoutingService.
 *
 * @covers \OCA\Procest\Service\BerichtenboxRoutingService
 */
class BerichtenboxRoutingServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var BerichtenboxRoutingService
	 */
	private BerichtenboxRoutingService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new BerichtenboxRoutingService($logger);
	}//end setUp()

	/**
	 * A confirmed burger routes to MijnOverheid.
	 *
	 * @return void
	 */
	public function testBurgerRoutesToMijnOverheid(): void {
		$result = $this->service->routeToBerichtenbox([
			'reference' => 'Z/2026/1/B01',
			'addressee' => [
				'type' => 'burger',
				'bsn' => '123456789',
				'messageBoxConfirmed' => true,
			],
		]);

		$this->assertSame('berichtenbox-mijnoverheid', $result['notificationChannel']);
		$this->assertNotEmpty($result['messageId']);
		$this->assertSame('systeem', $result['sentBy']);
	}//end testBurgerRoutesToMijnOverheid()

	/**
	 * A confirmed bedrijf routes to eHerkenning.
	 *
	 * @return void
	 */
	public function testBedrijfRoutesToEherkenning(): void {
		$result = $this->service->routeToBerichtenbox([
			'reference' => 'Z/2026/2/B01',
			'addressee' => [
				'type' => 'bedrijf',
				'oin' => '00000001234567890000',
				'messageBoxConfirmed' => true,
			],
		]);

		$this->assertSame('berichtenbox-eherkenning', $result['notificationChannel']);
	}//end testBedrijfRoutesToEherkenning()

	/**
	 * An unconfirmed channel falls back to print-post.
	 *
	 * @return void
	 */
	public function testFallbackToPrint(): void {
		$result = $this->service->routeToBerichtenbox([
			'reference' => 'Z/2026/3/B01',
			'addressee' => [
				'type' => 'burger',
				'bsn' => '987654321',
				'messageBoxConfirmed' => false,
			],
		]);

		$this->assertSame('print-post', $result['notificationChannel']);
	}//end testFallbackToPrint()
}//end class
