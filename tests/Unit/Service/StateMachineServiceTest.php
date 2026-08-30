<?php

/**
 * StateMachineService Unit Tests.
 *
 * Verifies the formal beschikking state-machine: permitted transitions, the
 * single back-edge, immutability boundaries, and that transition logging is
 * skipped gracefully when storage is unavailable.
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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StateMachineService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for StateMachineService.
 *
 * @covers \OCA\Dossiq\Service\StateMachineService
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
		$this->assertTrue($this->service->validateTransition('draft', 'approved-mandate'));
		$this->assertTrue($this->service->validateTransition('approved-mandate', 'signed'));
		$this->assertTrue($this->service->validateTransition('signed', 'sent'));
		$this->assertTrue($this->service->validateTransition('sent', 'received-confirmation'));
		$this->assertTrue($this->service->validateTransition('sent', 'archived'));
		$this->assertTrue($this->service->validateTransition('received-confirmation', 'archived'));
	}//end testForwardTransitionsAllowed()

	/**
	 * The single back-edge (akkoord-mandaat -> ontwerp) is permitted.
	 *
	 * @return void
	 */
	public function testBackEdgeAllowed(): void {
		$this->assertTrue($this->service->validateTransition('approved-mandate', 'draft'));
	}//end testBackEdgeAllowed()

	/**
	 * Skipping a state or reversing the machine is rejected.
	 *
	 * @return void
	 */
	public function testInvalidTransitionsRejected(): void {
		$this->assertFalse($this->service->validateTransition('draft', 'signed'));
		$this->assertFalse($this->service->validateTransition('sent', 'draft'));
		$this->assertFalse($this->service->validateTransition('archived', 'sent'));
		$this->assertFalse($this->service->validateTransition('signed', 'draft'));
		$this->assertFalse($this->service->validateTransition('unknown', 'draft'));
	}//end testInvalidTransitionsRejected()

	/**
	 * Immutability begins at ondertekend.
	 *
	 * @return void
	 */
	public function testImmutabilityBoundary(): void {
		$this->assertFalse($this->service->isImmutable('draft'));
		$this->assertFalse($this->service->isImmutable('approved-mandate'));
		$this->assertTrue($this->service->isImmutable('signed'));
		$this->assertTrue($this->service->isImmutable('sent'));
		$this->assertTrue($this->service->isImmutable('archived'));
	}//end testImmutabilityBoundary()

	/**
	 * logTransition returns an empty array when storage is unavailable.
	 *
	 * @return void
	 */
	public function testLogTransitionWithoutStorage(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->logTransition('besch-1', 'draft', 'approved-mandate');

		$this->assertSame([], $result);
	}//end testLogTransitionWithoutStorage()
}//end class
