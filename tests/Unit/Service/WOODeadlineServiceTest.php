<?php

/**
 * WOODeadlineService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Typed stub for the OpenRegister ObjectService.
 *
 * WOODeadlineService resolves a single case via ObjectService::find(), which
 * is called with named arguments (id:/register:/schema:). A bare addMethods()
 * magic mock rejects named arguments with "Unknown named parameter"; this typed
 * interface lets PHPUnit generate a mock whose signature accepts them.
 */
interface WOODeadlineObjectServiceStub {
	/**
	 * Find a single object by ID (real ObjectService::find()).
	 *
	 * @param int|string $id Object UUID
	 * @param mixed ...$args Remaining find() args (extend/files/register/schema).
	 *
	 * @return mixed
	 */
	public function find(int|string $id, ...$args): mixed;

	/**
	 * Save or update an object.
	 *
	 * @param mixed ...$args saveObject() arguments.
	 *
	 * @return mixed
	 */
	public function saveObject(...$args): mixed;
}//end interface

/**
 * Unit tests for WOODeadlineService.
 *
 * @covers \OCA\Dossiq\Service\WOODeadlineService
 */
class WOODeadlineServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var INotificationManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private INotificationManager $notificationManager;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var WOODeadlineService
	 */
	private WOODeadlineService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new WOODeadlineService(
			$this->settingsService,
			$this->notificationManager,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Calculate returns 28-day deadline from receipt date.
	 *
	 * Acceptance criterion: case created 2026-05-01 → expectedResolution 2026-05-29.
	 *
	 * @return void
	 */
	public function testCalculateReturns28DayDeadline(): void {
		$result = $this->service->calculate('2026-05-01');

		$this->assertSame('2026-05-29', $result['expectedResolution']);
		$this->assertSame('P28D', $result['processingPeriod']);
	}//end testCalculateReturns28DayDeadline()

	/**
	 * Calculate throws InvalidArgumentException for invalid date.
	 *
	 * @return void
	 */
	public function testCalculateThrowsForInvalidDate(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/invalid/i');

		$this->service->calculate('not-a-date');
	}//end testCalculateThrowsForInvalidDate()

	/**
	 * ExtendDeadline throws when extension already applied.
	 *
	 * Acceptance criterion: second extension attempt returns error.
	 *
	 * @return void
	 */
	public function testExtendDeadlineThrowsOnSecondExtension(): void {
		$objectServiceMock = $this->createMock(WOODeadlineObjectServiceStub::class);
		// Return a case that already has deadlineVerlengd = 1.
		$objectServiceMock->method('find')->willReturn([
			'id' => 'case-uuid-001',
			'expectedResolution' => '2026-05-29',
			'deadlineVerlengd' => 1,
		]);

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['case_schema', '', 'case'],
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Only one deadline extension/i');

		$this->service->extendDeadline('case-uuid-001', 'Complex request');
	}//end testExtendDeadlineThrowsOnSecondExtension()

	/**
	 * ExtendDeadline throws when reason is empty.
	 *
	 * @return void
	 */
	public function testExtendDeadlineThrowsForEmptyReason(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/reason is required/i');

		$this->service->extendDeadline('case-uuid-001', '');
	}//end testExtendDeadlineThrowsForEmptyReason()

	/**
	 * CheckAndWarn returns warned=false when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testCheckAndWarnReturnsFalseWhenORUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->checkAndWarn('case-uuid-001', 'j.dejong');

		$this->assertFalse($result['warned']);
		$this->assertStringContainsString('OpenRegister', $result['reason']);
	}//end testCheckAndWarnReturnsFalseWhenORUnavailable()

	/**
	 * CheckAndWarn returns isOverdue=false and warned=false for a distant deadline.
	 *
	 * @return void
	 */
	public function testCheckAndWarnReturnsFalseForDistantDeadline(): void {
		$objectServiceMock = $this->createMock(WOODeadlineObjectServiceStub::class);
		$objectServiceMock->method('find')->willReturn([
			'id' => 'case-uuid-001',
			'expectedResolution' => '2099-12-31',
		]);

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['case_schema', '', 'case'],
		]);

		$result = $this->service->checkAndWarn('case-uuid-001', 'j.dejong');

		$this->assertFalse($result['isOverdue']);
		$this->assertFalse($result['warned']);
	}//end testCheckAndWarnReturnsFalseForDistantDeadline()

}//end class
