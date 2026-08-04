<?php

/**
 * AiController::auditIndex() Unit Tests
 *
 * Asserts the audit-listing stub is gone (no more "implement with
 * OpenRegister object listing" placeholder), filters/paging params pass
 * through to AiService::listAuditEntries(), and failures are handled
 * without leaking exception internals as a 200.
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
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\AiController;
use OCA\Procest\Service\AiService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AiController::auditIndex().
 *
 * @covers \OCA\Procest\Controller\AiController
 */
class AiControllerAuditIndexTest extends TestCase
{

    private AiService $aiService;

    private IUserSession $userSession;

    private IRequest $request;

    private LoggerInterface $logger;

    private AiController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->aiService       = $this->createMock(AiService::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->request         = $this->createMock(IRequest::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('behandelaar-1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new AiController(
            appName: 'procest',
            request: $this->request,
            aiService: $this->aiService,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * The stub placeholder message is gone; a real entries/paging shape is
     * returned instead.
     *
     * @return void
     */
    public function testAuditIndexReturnsRealEntriesNotStub(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['caseId', null, 'case-a'],
            ['type', null, null],
            ['limit', '50', '50'],
            ['offset', '0', '0'],
        ]);

        $entries = [['id' => 'e1', 'type' => 'classification', 'caseId' => 'case-a']];

        $this->aiService->expects($this->once())
            ->method('listAuditEntries')
            ->with(
                $this->callback(fn (array $filters) => ($filters['caseId'] ?? null) === 'case-a'),
                50,
                0,
            )
            ->willReturn([
                'entries' => $entries,
                'total'   => null,
                'limit'   => 50,
                'offset'  => 0,
            ]);

        $response = $this->controller->auditIndex();
        $data     = $response->getData();

        $this->assertTrue($data['success']);
        $this->assertSame($entries, $data['entries']);
        $this->assertArrayNotHasKey('message', $data);
        $this->assertStringNotContainsString(
            'implement with OpenRegister object listing',
            json_encode($data)
        );
    }//end testAuditIndexReturnsRealEntriesNotStub()

    /**
     * caseId/type/limit/offset request params pass straight through to the
     * service.
     *
     * @return void
     */
    public function testAuditIndexPassesFiltersAndPagingThrough(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['caseId', null, 'case-b'],
            ['type', null, 'summary'],
            ['limit', '50', '10'],
            ['offset', '0', '20'],
        ]);

        $this->aiService->expects($this->once())
            ->method('listAuditEntries')
            ->with(
                ['caseId' => 'case-b', 'type' => 'summary'],
                10,
                20,
            )
            ->willReturn(['entries' => [], 'total' => null, 'limit' => 10, 'offset' => 20]);

        $this->controller->auditIndex();
    }//end testAuditIndexPassesFiltersAndPagingThrough()

    /**
     * Unauthenticated requests are rejected before the service is called.
     *
     * @return void
     */
    public function testAuditIndexRejectsUnauthenticated(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = new AiController(
            appName: 'procest',
            request: $this->request,
            aiService: $this->aiService,
            userSession: $userSession,
            logger: $this->logger,
        );

        $this->aiService->expects($this->never())->method('listAuditEntries');

        $response = $controller->auditIndex();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testAuditIndexRejectsUnauthenticated()

    /**
     * A service-level failure returns a 500 rather than crashing.
     *
     * @return void
     */
    public function testAuditIndexHandlesServiceFailure(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['caseId', null, null],
            ['type', null, null],
            ['limit', '50', '50'],
            ['offset', '0', '0'],
        ]);

        $this->aiService->method('listAuditEntries')
            ->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->auditIndex();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }//end testAuditIndexHandlesServiceFailure()
}//end class
