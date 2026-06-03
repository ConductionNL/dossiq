<?php

/**
 * WOORedactionService Unit Tests
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
use OCA\Procest\Service\WOORedactionService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WOORedactionService.
 *
 * @covers \OCA\Procest\Service\WOORedactionService
 */
class WOORedactionServiceTest extends TestCase
{

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppManager $appManager;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var WOORedactionService
     */
    private WOORedactionService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new WOORedactionService(
            $this->settingsService,
            $this->appManager,
            $this->logger,
        );
    }//end setUp()

    /**
     * IsDocuDeskInstalled returns false when the app is not installed.
     *
     * @return void
     */
    public function testIsDocuDeskInstalledReturnsFalseWhenNotInstalled(): void
    {
        $this->appManager->method('isInstalled')->willReturn(false);
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $this->assertFalse($this->service->isDocuDeskInstalled());
    }//end testIsDocuDeskInstalledReturnsFalseWhenNotInstalled()

    /**
     * IsDocuDeskInstalled returns true when the app is installed and enabled.
     *
     * @return void
     */
    public function testIsDocuDeskInstalledReturnsTrueWhenAvailable(): void
    {
        $this->appManager->method('isInstalled')->willReturn(true);
        $this->appManager->method('isEnabledForUser')->willReturn(true);

        $this->assertTrue($this->service->isDocuDeskInstalled());
    }//end testIsDocuDeskInstalledReturnsTrueWhenAvailable()

    /**
     * QueueForRedaction returns manual mode when Docudesk is not installed.
     *
     * Acceptance criterion: Docudesk not installed → UI falls back to manual upload flow.
     *
     * @return void
     */
    public function testQueueForRedactionFallsBackToManualWhenNoDocuDesk(): void
    {
        $this->appManager->method('isInstalled')->willReturn(false);
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $documents = [
            ['id' => 'doc-001', 'title' => 'Vergunning A'],
            ['id' => 'doc-002', 'title' => 'Rapport B'],
        ];

        $result = $this->service->queueForRedaction('case-uuid-001', $documents);

        $this->assertSame('manual', $result['mode']);
        $this->assertEmpty($result['queued']);
        $this->assertCount(2, $result['manual']);
        $this->assertSame('awaiting_manual_redaction', $result['manual'][0]['status']);
    }//end testQueueForRedactionFallsBackToManualWhenNoDocuDesk()

    /**
     * QueueForRedaction routes documents via Docudesk when installed.
     *
     * Acceptance criterion: Docudesk installed → documents queued in Docudesk.
     *
     * @return void
     */
    public function testQueueForRedactionUsesDocuDeskWhenInstalled(): void
    {
        $this->appManager->method('isInstalled')->willReturn(true);
        $this->appManager->method('isEnabledForUser')->willReturn(true);

        $documents = [['id' => 'doc-001', 'title' => 'Rapport C']];

        $result = $this->service->queueForRedaction('case-uuid-001', $documents);

        $this->assertSame('docudesk', $result['mode']);
        $this->assertCount(1, $result['queued']);
        $this->assertEmpty($result['manual']);
        $this->assertSame('queued', $result['queued'][0]['status']);
    }//end testQueueForRedactionUsesDocuDeskWhenInstalled()

    /**
     * QueueForRedaction returns empty result for empty document list.
     *
     * @return void
     */
    public function testQueueForRedactionReturnsEmptyForNoDocuments(): void
    {
        $result = $this->service->queueForRedaction('case-uuid-001', []);

        $this->assertSame('none', $result['mode']);
        $this->assertEmpty($result['queued']);
        $this->assertEmpty($result['manual']);
    }//end testQueueForRedactionReturnsEmptyForNoDocuments()

}//end class
