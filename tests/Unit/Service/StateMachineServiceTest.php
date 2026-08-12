<?php

/**
 * StateMachineService Unit Tests.
 *
 * Verifies the formal beschikking state-machine: permitted transitions, the
 * single back-edge, immutability boundaries, and that transition logging is
 * skipped gracefully when storage is unavailable.
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

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\StateMachineService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for StateMachineService.
 *
 * @covers \OCA\Procest\Service\StateMachineService
 */
class StateMachineServiceTest extends TestCase {
	/**
	 * The settings service mock.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The service under test.
	 *
	 * @var StateMachineService
	 */
	private StateMachineService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new StateMachineService($this->settingsService, $logger);
	}//end setUp()

	/**
	 * The happy-path forward transitions are all permitted.
	 *
	 * @return void
	 */
	public function testForwardTransitionsAllowed(): void {
		$this->assertTrue($this->service->validateTransition('ontwerp', 'akkoord-mandaat'));
		$this->assertTrue($this->service->validateTransition('akkoord-mandaat', 'ondertekend'));
		$this->assertTrue($this->service->validateTransition('ondertekend', 'verzonden'));
		$this->assertTrue($this->service->validateTransition('verzonden', 'ontvangen-bevestiging'));
		$this->assertTrue($this->service->validateTransition('verzonden', 'gearchiveerd'));
		$this->assertTrue($this->service->validateTransition('ontvangen-bevestiging', 'gearchiveerd'));
	}//end testForwardTransitionsAllowed()

	/**
	 * The single back-edge (akkoord-mandaat -> ontwerp) is permitted.
	 *
	 * @return void
	 */
	public function testBackEdgeAllowed(): void {
		$this->assertTrue($this->service->validateTransition('akkoord-mandaat', 'ontwerp'));
	}//end testBackEdgeAllowed()

	/**
	 * Skipping a state or reversing the machine is rejected.
	 *
	 * @return void
	 */
	public function testInvalidTransitionsRejected(): void {
		$this->assertFalse($this->service->validateTransition('ontwerp', 'ondertekend'));
		$this->assertFalse($this->service->validateTransition('verzonden', 'ontwerp'));
		$this->assertFalse($this->service->validateTransition('gearchiveerd', 'verzonden'));
		$this->assertFalse($this->service->validateTransition('ondertekend', 'ontwerp'));
		$this->assertFalse($this->service->validateTransition('onbekend', 'ontwerp'));
	}//end testInvalidTransitionsRejected()

	/**
	 * Immutability begins at ondertekend.
	 *
	 * @return void
	 */
	public function testImmutabilityBoundary(): void {
		$this->assertFalse($this->service->isImmutable('ontwerp'));
		$this->assertFalse($this->service->isImmutable('akkoord-mandaat'));
		$this->assertTrue($this->service->isImmutable('ondertekend'));
		$this->assertTrue($this->service->isImmutable('verzonden'));
		$this->assertTrue($this->service->isImmutable('gearchiveerd'));
	}//end testImmutabilityBoundary()

	/**
	 * logTransition returns an empty array when storage is unavailable.
	 *
	 * @return void
	 */
	public function testLogTransitionWithoutStorage(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->logTransition('besch-1', 'ontwerp', 'akkoord-mandaat');

		$this->assertSame([], $result);
	}//end testLogTransitionWithoutStorage()
}//end class
