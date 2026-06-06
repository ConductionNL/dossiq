<?php

/**
 * WOODocumentAssessmentService Unit Tests
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
use OCA\Procest\Service\WOODocumentAssessmentService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WOODocumentAssessmentService.
 *
 * @covers \OCA\Procest\Service\WOODocumentAssessmentService
 */
class WOODocumentAssessmentServiceTest extends TestCase
{

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var WOODocumentAssessmentService
     */
    private WOODocumentAssessmentService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new WOODocumentAssessmentService(
            $this->settingsService,
            $this->userSession,
            $this->logger,
        );
    }//end setUp()

    /**
     * Validate returns no errors for a valid 'openbaar' assessment.
     *
     * @return void
     */
    public function testValidatePassesForOpenbarWithoutGrounds(): void
    {
        $errors = $this->service->validate([
            'documentRef'    => 'doc-uuid-001',
            'classification' => 'openbaar',
        ]);

        $this->assertEmpty($errors);
    }//end testValidatePassesForOpenbarWithoutGrounds()

    /**
     * Validate returns error when classification is 'deels_openbaar' and no weigeringsgrond.
     *
     * Acceptance criterion: classification 'deels openbaar' without weigeringsgrond → error.
     *
     * @return void
     */
    public function testValidateRequiresWeigeringsgrondForDeelsOpenbaar(): void
    {
        $errors = $this->service->validate([
            'documentRef'    => 'doc-uuid-001',
            'classification' => 'deels_openbaar',
        ]);

        $this->assertArrayHasKey('weigeringsgronden', $errors);
        $this->assertStringContainsString('weigeringsgrond', $errors['weigeringsgronden']);
    }//end testValidateRequiresWeigeringsgrondForDeelsOpenbaar()

    /**
     * Validate returns error when classification is 'niet_openbaar' and no weigeringsgrond.
     *
     * @return void
     */
    public function testValidateRequiresWeigeringsgrondForNietOpenbaar(): void
    {
        $errors = $this->service->validate([
            'documentRef'    => 'doc-uuid-001',
            'classification' => 'niet_openbaar',
        ]);

        $this->assertArrayHasKey('weigeringsgronden', $errors);
    }//end testValidateRequiresWeigeringsgrondForNietOpenbaar()

    /**
     * Validate passes for 'niet_openbaar' with a valid weigeringsgrond.
     *
     * @return void
     */
    public function testValidatePassesForNietOpenbaarWithGrond(): void
    {
        $errors = $this->service->validate([
            'documentRef'       => 'doc-uuid-001',
            'classification'    => 'niet_openbaar',
            'weigeringsgronden' => ['5.1.5'],
        ]);

        $this->assertEmpty($errors);
    }//end testValidatePassesForNietOpenbaarWithGrond()

    /**
     * Validate returns error for invalid classification value.
     *
     * @return void
     */
    public function testValidateRejectsInvalidClassification(): void
    {
        $errors = $this->service->validate([
            'documentRef'    => 'doc-uuid-001',
            'classification' => 'geheim',
        ]);

        $this->assertArrayHasKey('classification', $errors);
    }//end testValidateRejectsInvalidClassification()

    /**
     * Validate returns error when documentRef is missing.
     *
     * @return void
     */
    public function testValidateRequiresDocumentRef(): void
    {
        $errors = $this->service->validate([
            'classification' => 'openbaar',
        ]);

        $this->assertArrayHasKey('documentRef', $errors);
    }//end testValidateRequiresDocumentRef()

    /**
     * BulkUpsert throws RuntimeException when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testBulkUpsertThrowsWhenORUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenRegister/i');

        $this->service->bulkUpsert('case-uuid-001', [
            ['documentRef' => 'doc-001', 'classification' => 'openbaar'],
        ]);
    }//end testBulkUpsertThrowsWhenORUnavailable()

    /**
     * GetOutstanding returns count=0 when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testGetOutstandingReturnsZeroWhenORUnavailable(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $result = $this->service->getOutstanding('case-uuid-001');

        $this->assertSame(0, $result['count']);
        $this->assertEmpty($result['documents']);
    }//end testGetOutstandingReturnsZeroWhenORUnavailable()

    /**
     * AllDocumentsAssessed returns true when outstanding count is zero.
     *
     * @return void
     */
    public function testAllDocumentsAssessedReturnsTrueWhenNoneOutstanding(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $result = $this->service->allDocumentsAssessed('case-uuid-001');

        $this->assertTrue($result);
    }//end testAllDocumentsAssessedReturnsTrueWhenNoneOutstanding()

}//end class
