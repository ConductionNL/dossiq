<?php

/**
 * BerichtenboxRoutingService Unit Tests.
 *
 * Verifies channel resolution: burger -> MijnOverheid, bedrijf -> eHerkenning,
 * and the print-post fallback when the addressee has not activated a digital
 * channel.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\BerichtenboxRoutingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BerichtenboxRoutingService.
 *
 * @covers \OCA\Dossiq\Service\BerichtenboxRoutingService
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

	/**
	 * REQ-WRN-001, "The old router cannot fake a send": it picks a channel and
	 * nothing more. No message id, no sent moment and no sender, because no
	 * transport was called and any of those would read as a send.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function testTheRouterNeverAnswersAMessageId(): void {
		$addressees = [
			['type' => 'burger', 'bsn' => '123456789', 'messageBoxConfirmed' => true],
			['type' => 'bedrijf', 'oin' => '00000001234567890000', 'messageBoxConfirmed' => true],
			['type' => 'burger', 'bsn' => '987654321', 'messageBoxConfirmed' => false],
			[],
		];

		foreach ($addressees as $addressee) {
			$result = $this->service->routeToBerichtenbox(['reference' => 'Z/2026/9/B01', 'addressee' => $addressee]);

			$this->assertArrayNotHasKey('messageId', $result);
			$this->assertArrayNotHasKey('sentOn', $result);
			$this->assertArrayNotHasKey('sentBy', $result);
			$this->assertNotSame('', $result['notificationChannel']);
		}
	}//end testTheRouterNeverAnswersAMessageId()
}//end class
