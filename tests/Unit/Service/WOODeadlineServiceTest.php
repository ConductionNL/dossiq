<?php

/**
 * WOODeadlineService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\WOODeadlineService;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WOODeadlineService.
 *
 * @covers \OCA\Procest\Service\WOODeadlineService
 */
class WOODeadlineServiceTest extends TestCase
{

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
    protected function setUp(): void
    {
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

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
    public function testCalculateReturns28DayDeadline(): void
    {
        $result = $this->service->calculate('2026-05-01');

        $this->assertSame('2026-05-29', $result['expectedResolution']);
        $this->assertSame('P28D', $result['processingPeriod']);
    }//end testCalculateReturns28DayDeadline()

    /**
     * Calculate throws InvalidArgumentException for invalid date.
     *
     * @return void
     */
    public function testCalculateThrowsForInvalidDate(): void
    {
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
    public function testExtendDeadlineThrowsOnSecondExtension(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObject'])
            ->getMock();
        // Return a case that already has deadlineVerlengd = 1.
        $objectServiceMock->method('findObject')->willReturn([
            'id'                 => 'case-uuid-001',
            'expectedResolution' => '2026-05-29',
            'deadlineVerlengd'   => 1,
        ]);

        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturnMap([
            ['register', '', 'procest'],
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
    public function testExtendDeadlineThrowsForEmptyReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reason is required/i');

        $this->service->extendDeadline('case-uuid-001', '');
    }//end testExtendDeadlineThrowsForEmptyReason()

    /**
     * CheckAndWarn returns warned=false when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testCheckAndWarnReturnsFalseWhenORUnavailable(): void
    {
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
    public function testCheckAndWarnReturnsFalseForDistantDeadline(): void
    {
        $objectServiceMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObject'])
            ->getMock();
        $objectServiceMock->method('findObject')->willReturn([
            'id'                 => 'case-uuid-001',
            'expectedResolution' => '2099-12-31',
        ]);

        $this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
        $this->settingsService->method('getConfigValue')->willReturnMap([
            ['register', '', 'procest'],
            ['case_schema', '', 'case'],
        ]);

        $result = $this->service->checkAndWarn('case-uuid-001', 'j.dejong');

        $this->assertFalse($result['isOverdue']);
        $this->assertFalse($result['warned']);
    }//end testCheckAndWarnReturnsFalseForDistantDeadline()

}//end class
