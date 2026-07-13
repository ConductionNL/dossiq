<?php

/**
 * Iv3ReportController Unit Tests
 *
 * Covers RBAC on the report endpoint (allowed group / admin fallback /
 * plain-user denial / unauthenticated), the JSON/CSV format switch, and
 * that the taakveld reference endpoint is open to any authenticated user.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\Iv3ReportController;
use OCA\Procest\Service\Iv3ReportService;
use OCA\Procest\Service\Iv3TaakveldList;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Iv3ReportController.
 *
 * @covers \OCA\Procest\Controller\Iv3ReportController
 */
class Iv3ReportControllerTest extends TestCase
{
    /**
     * Mocked reporting service.
     *
     * @var Iv3ReportService|\PHPUnit\Framework\MockObject\MockObject
     */
    private Iv3ReportService $reportService;

    /**
     * Mocked group manager.
     *
     * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private IGroupManager $groupManager;

    /**
     * Mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * Mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * Build a controller instance for a given user id (null = unauthenticated).
     *
     * @param string|null $uid The Nextcloud user id, or null for no session.
     *
     * @return Iv3ReportController
     */
    private function makeController(?string $uid): Iv3ReportController
    {
        $userSession = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $userSession->method('getUser')->willReturn($user);
        }

        return new Iv3ReportController(
            appName: 'procest',
            request: $this->request,
            userSession: $userSession,
            groupManager: $this->groupManager,
            reportService: $this->reportService,
            taakveldList: new Iv3TaakveldList(),
            logger: $this->logger,
        );
    }//end makeController()

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->reportService = $this->createMock(Iv3ReportService::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->request       = $this->createMock(IRequest::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * A user in an allowed group receives the JSON report.
     *
     * @return void
     */
    public function testReportAllowedGroupReturnsJson(): void
    {
        $this->groupManager->method('isInGroup')
            ->willReturnCallback(fn ($uid, $group) => $group === 'controllers');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->reportService->method('generateQuarterlyReport')
            ->with(2026, 2)
            ->willReturn(['year' => 2026, 'quarter' => 2, 'perTaakveld' => [], 'uncategorized' => null]);

        $controller = $this->makeController('controller-1');
        $response   = $controller->report(2026, 2, 'json');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(2026, $response->getData()['year']);
    }//end testReportAllowedGroupReturnsJson()

    /**
     * An NC admin outside the allowed groups is still permitted (defensive
     * fallback, mirrors AiAuditExportController).
     *
     * @return void
     */
    public function testReportAdminFallbackAllowed(): void
    {
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->reportService->method('generateQuarterlyReport')
            ->willReturn(['year' => 2026, 'quarter' => 2, 'perTaakveld' => [], 'uncategorized' => null]);

        $controller = $this->makeController('admin-1');
        $response   = $controller->report(2026, 2, 'json');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testReportAdminFallbackAllowed()

    /**
     * A plain user (no group, not admin) is denied with 403.
     *
     * @return void
     */
    public function testReportDeniesPlainUser(): void
    {
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->reportService->expects($this->never())->method('generateQuarterlyReport');

        $controller = $this->makeController('plain-user');
        $response   = $controller->report(2026, 2, 'json');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testReportDeniesPlainUser()

    /**
     * An unauthenticated request is rejected with 401.
     *
     * @return void
     */
    public function testReportRejectsUnauthenticated(): void
    {
        $controller = $this->makeController(null);
        $response   = $controller->report(2026, 2, 'json');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testReportRejectsUnauthenticated()

    /**
     * format=csv returns a CSV file download, not a JSON body.
     *
     * @return void
     */
    public function testReportCsvFormatReturnsDownload(): void
    {
        $this->groupManager->method('isInGroup')->willReturn(true);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->reportService->method('generateQuarterlyReport')
            ->willReturn(['year' => 2026, 'quarter' => 2, 'perTaakveld' => [], 'uncategorized' => null]);
        $this->reportService->method('asCsv')->willReturn("taakveld,label\n");

        $controller = $this->makeController('controller-1');
        $response   = $controller->report(2026, 2, 'csv');

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        $this->assertSame('text/csv', $response->getHeaders()['Content-Type']);
    }//end testReportCsvFormatReturnsDownload()

    /**
     * Invalid quarter is rejected with 400 before the service is called.
     *
     * @return void
     */
    public function testReportRejectsInvalidQuarter(): void
    {
        $this->groupManager->method('isInGroup')->willReturn(true);
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->reportService->expects($this->never())->method('generateQuarterlyReport');

        $controller = $this->makeController('controller-1');
        $response   = $controller->report(2026, 5, 'json');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testReportRejectsInvalidQuarter()

    /**
     * The taakveld reference list is available to any authenticated user
     * with no group requirement.
     *
     * @return void
     */
    public function testTaakveldenOpenToAnyAuthenticatedUser(): void
    {
        $controller = $this->makeController('plain-user');
        $response   = $controller->taakvelden();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertNotEmpty($response->getData()['taakvelden']);
    }//end testTaakveldenOpenToAnyAuthenticatedUser()

    /**
     * The taakveld reference list rejects unauthenticated requests.
     *
     * @return void
     */
    public function testTaakveldenRejectsUnauthenticated(): void
    {
        $controller = $this->makeController(null);
        $response   = $controller->taakvelden();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testTaakveldenRejectsUnauthenticated()
}//end class
