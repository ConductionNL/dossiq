<?php

/**
 * AiAuditExportController Unit Tests
 *
 * Covers RBAC (allowed group / admin fallback / plain-user denial /
 * unauthenticated) and the CSV/JSON export shape.
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
 * @spec openspec/changes/ai-oversight-log/tasks.md#2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\AiAuditExportController;
use OCA\Procest\Service\AiService;
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
 * Unit tests for AiAuditExportController::export().
 *
 * @covers \OCA\Procest\Controller\AiAuditExportController
 */
class AiAuditExportControllerTest extends TestCase
{

    private AiService $aiService;

    private IGroupManager $groupManager;

    private IUserSession $userSession;

    private IRequest $request;

    private LoggerInterface $logger;

    /**
     * Build a controller instance for a given user id (null = unauthenticated).
     *
     * @param string|null $uid The Nextcloud user id, or null for no session.
     *
     * @return AiAuditExportController
     */
    private function makeController(?string $uid): AiAuditExportController
    {
        $userSession = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $userSession->method('getUser')->willReturn($user);
        }

        return new AiAuditExportController(
            appName: 'procest',
            request: $this->request,
            userSession: $userSession,
            groupManager: $this->groupManager,
            aiService: $this->aiService,
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
        $this->aiService    = $this->createMock(AiService::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->request      = $this->createMock(IRequest::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * A user in an allowed group receives a CSV download with a header row.
     *
     * @return void
     */
    public function testExportAllowedGroupReturnsCsv(): void
    {
        $this->groupManager->method('isInGroup')
            ->willReturnCallback(fn ($uid, $group) => $group === 'auditors');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->request->method('getParam')->willReturnMap([
            ['caseId', null, null],
            ['type', null, null],
            ['format', 'csv', 'csv'],
        ]);

        $this->aiService->method('listAuditEntries')->willReturn([
            'entries' => [
                [
                    'id'         => 'e1',
                    'type'       => 'classification',
                    'action'     => 'suggestion',
                    'caseId'     => 'case-a',
                    'userId'     => 'behandelaar-1',
                    'timestamp'  => '2026-07-12T10:00:00+00:00',
                    'suggestion' => ['documentType' => 'brief'],
                ],
            ],
            'total'   => null,
            'limit'   => 200,
            'offset'  => 0,
        ]);

        $controller = $this->makeController('auditor-1');
        $response   = $controller->export();

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
        $this->assertSame('text/csv', $response->getHeaders()['Content-Type']);

        $csv = $response->render();
        $this->assertStringContainsString('id,created,type,action,caseId', $csv);
        $this->assertStringContainsString('e1', $csv);
        $this->assertStringContainsString('classification', $csv);
        // Array-valued field flattened to a JSON string cell.
        $this->assertStringContainsString('documentType', $csv);
    }//end testExportAllowedGroupReturnsCsv()

    /**
     * An NC admin outside the allowed groups is still permitted (defensive
     * fallback, mirrors the parafering export).
     *
     * @return void
     */
    public function testExportAdminFallbackAllowed(): void
    {
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->request->method('getParam')->willReturnMap([
            ['caseId', null, null],
            ['type', null, null],
            ['format', 'csv', 'csv'],
        ]);

        $this->aiService->method('listAuditEntries')
            ->willReturn(['entries' => [], 'total' => null, 'limit' => 200, 'offset' => 0]);

        $controller = $this->makeController('admin-1');
        $response   = $controller->export();

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }//end testExportAdminFallbackAllowed()

    /**
     * A user in none of the allowed groups (and not an admin) is denied
     * with 403 and no data.
     *
     * @return void
     */
    public function testExportDeniesPlainUser(): void
    {
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->aiService->expects($this->never())->method('listAuditEntries');

        $controller = $this->makeController('plain-user');
        $response   = $controller->export();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testExportDeniesPlainUser()

    /**
     * An unauthenticated request is rejected with 401.
     *
     * @return void
     */
    public function testExportRejectsUnauthenticated(): void
    {
        $controller = $this->makeController(null);
        $response   = $controller->export();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testExportRejectsUnauthenticated()

    /**
     * format=json returns the raw entries array shape instead of CSV.
     *
     * @return void
     */
    public function testExportFormatJsonReturnsRawEntries(): void
    {
        $this->groupManager->method('isInGroup')
            ->willReturnCallback(fn ($uid, $group) => $group === 'auditors');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->request->method('getParam')->willReturnMap([
            ['caseId', null, null],
            ['type', null, null],
            ['format', 'csv', 'json'],
        ]);

        $entries = [['id' => 'e1', 'type' => 'qa']];
        $this->aiService->method('listAuditEntries')
            ->willReturn(['entries' => $entries, 'total' => null, 'limit' => 200, 'offset' => 0]);

        $controller = $this->makeController('auditor-1');
        $response   = $controller->export();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertSame($entries, $data['entries']);
        $this->assertSame(1, $data['count']);
    }//end testExportFormatJsonReturnsRawEntries()
}//end class
