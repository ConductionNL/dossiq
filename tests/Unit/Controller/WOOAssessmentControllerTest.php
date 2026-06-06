<?php

/**
 * WOOAssessmentController Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
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

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\WOOAssessmentController;
use OCA\Procest\Service\WOODeadlineService;
use OCA\Procest\Service\WOODecisionService;
use OCA\Procest\Service\WOODocumentAssessmentService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WOOAssessmentController.
 *
 * @covers \OCA\Procest\Controller\WOOAssessmentController
 */
class WOOAssessmentControllerTest extends TestCase
{

    /**
     * @var WOODocumentAssessmentService|\PHPUnit\Framework\MockObject\MockObject
     */
    private WOODocumentAssessmentService $assessmentService;

    /**
     * @var WOODeadlineService|\PHPUnit\Framework\MockObject\MockObject
     */
    private WOODeadlineService $deadlineService;

    /**
     * @var WOODecisionService|\PHPUnit\Framework\MockObject\MockObject
     */
    private WOODecisionService $decisionService;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private IGroupManager $groupManager;

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var WOOAssessmentController
     */
    private WOOAssessmentController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->assessmentService = $this->createMock(WOODocumentAssessmentService::class);
        $this->deadlineService   = $this->createMock(WOODeadlineService::class);
        $this->decisionService   = $this->createMock(WOODecisionService::class);
        $this->userSession       = $this->createMock(IUserSession::class);
        $this->groupManager      = $this->createMock(IGroupManager::class);
        $this->request           = $this->createMock(IRequest::class);
        $this->logger            = $this->createMock(LoggerInterface::class);

        $this->controller = new WOOAssessmentController(
            'procest',
            $this->request,
            $this->assessmentService,
            $this->deadlineService,
            $this->decisionService,
            $this->userSession,
            $this->groupManager,
            $this->logger,
        );
    }//end setUp()

    /**
     * BulkAssess returns 401 when user is not authenticated.
     *
     * @return void
     */
    public function testBulkAssessReturns401WhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->bulkAssess('case-uuid-001');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testBulkAssessReturns401WhenNotAuthenticated()

    /**
     * BulkAssess returns 200 with result when authenticated and authorized.
     *
     * @return void
     */
    public function testBulkAssessReturnsResultWhenAuthenticated(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('j.dejong');
        $this->userSession->method('getUser')->willReturn($user);

        // Admin → bypass case-level check.
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->request->method('getParam')->willReturnMap([
            ['assessments', [], [
                ['documentRef' => 'doc-001', 'classification' => 'openbaar'],
            ]],
        ]);

        $this->assessmentService->method('bulkUpsert')->willReturn([
            'saved'       => [['id' => 'assessment-001']],
            'errors'      => [],
            'outstanding' => ['count' => 0, 'documents' => []],
        ]);

        $response = $this->controller->bulkAssess('case-uuid-001');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('saved', $data);
    }//end testBulkAssessReturnsResultWhenAuthenticated()

    /**
     * ExtendDeadline returns 400 when reason is empty.
     *
     * @return void
     */
    public function testExtendDeadlineReturns400ForEmptyReason(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('j.dejong');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->request->method('getParam')->willReturnMap([
            ['reason', '', ''],
        ]);

        $this->deadlineService
            ->method('extendDeadline')
            ->willThrowException(new \InvalidArgumentException('A reason is required'));

        $response = $this->controller->extendDeadline('case-uuid-001');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testExtendDeadlineReturns400ForEmptyReason()

    /**
     * CreateDecision returns 422 when outstanding documents exist.
     *
     * Acceptance criterion: unassessed document → blocked with explicit error.
     *
     * @return void
     */
    public function testCreateDecisionReturns422WhenDocumentsOutstanding(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('j.dejong');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->request->method('getParam')->willReturnMap([
            ['decision', [], []],
        ]);

        $this->decisionService
            ->method('assembleDecision')
            ->willThrowException(new \InvalidArgumentException('Cannot create besluit: 3 document(s) still need assessment.'));

        $response = $this->controller->createDecision('case-uuid-001');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testCreateDecisionReturns422WhenDocumentsOutstanding()

}//end class
