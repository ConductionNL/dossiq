<?php

/**
 * DsoController Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#V09
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Controller\DsoController;
use OCA\Procest\Service\BeschikkingGenerationService;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SamenwerkverzoekService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Controller\DsoController
 */
class DsoControllerTest extends TestCase
{

    private IRequest $request;

    private DsoCaseService $dsoCaseService;

    private BeschikkingGenerationService $beschikkingService;

    private SamenwerkverzoekService $samenwerkService;

    private SettingsService $settingsService;

    private IUserSession $userSession;

    private IGroupManager $groupManager;

    private IEventDispatcher $dispatcher;

    private LoggerInterface $logger;

    private DsoController $controller;

    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->dsoCaseService     = $this->createMock(DsoCaseService::class);
        $this->beschikkingService = $this->createMock(BeschikkingGenerationService::class);
        $this->samenwerkService   = $this->createMock(SamenwerkverzoekService::class);
        $this->settingsService    = $this->createMock(SettingsService::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->dispatcher         = $this->createMock(IEventDispatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new DsoController(
            appName: Application::APP_ID,
            request: $this->request,
            dsoCaseService: $this->dsoCaseService,
            beschikkingService: $this->beschikkingService,
            samenwerkService: $this->samenwerkService,
            settingsService: $this->settingsService,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            dispatcher: $this->dispatcher,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * dashboard() returns 401 when no user is authenticated.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V09
     */
    public function testDashboardReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession
            ->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $response = $this->controller->dashboard();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testDashboardReturnsUnauthorizedWhenNoUser()

    /**
     * transitionStatus() returns 400 when newStatus is missing.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V09
     */
    public function testTransitionStatusReturnsBadRequestWhenStatusMissing(): void
    {
        $mockUser = $this->createMock(\OCP\IUser::class);
        $this->userSession
            ->method('getUser')
            ->willReturn($mockUser);

        $this->request
            ->method('getParams')
            ->willReturn([]);

        $response = $this->controller->transitionStatus(caseId: 'test-id');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testTransitionStatusReturnsBadRequestWhenStatusMissing()

    /**
     * transitionStatus() returns 400 when status value is invalid.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V09
     */
    public function testTransitionStatusReturnsBadRequestForInvalidStatus(): void
    {
        $mockUser = $this->createMock(\OCP\IUser::class);
        $this->userSession
            ->method('getUser')
            ->willReturn($mockUser);

        $this->request
            ->method('getParams')
            ->willReturn(['newStatus' => 'invalid_value']);

        $response = $this->controller->transitionStatus(caseId: 'test-id');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testTransitionStatusReturnsBadRequestForInvalidStatus()

    /**
     * generateBeschikking() returns 400 when outcome is invalid.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V09
     */
    public function testGenerateBeschikkingReturnsBadRequestForInvalidOutcome(): void
    {
        $mockUser = $this->createMock(\OCP\IUser::class);
        $this->userSession
            ->method('getUser')
            ->willReturn($mockUser);

        $this->request
            ->method('getParams')
            ->willReturn(['outcome' => 'unknown']);

        $response = $this->controller->generateBeschikking(caseId: 'test-id');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testGenerateBeschikkingReturnsBadRequestForInvalidOutcome()

    /**
     * initiateSamenwerking() returns 400 when aangezochtBevoegdGezag is missing.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V09
     */
    public function testInitiateSamenwerkingReturnsBadRequestWhenGezagMissing(): void
    {
        $mockUser = $this->createMock(\OCP\IUser::class);
        $this->userSession
            ->method('getUser')
            ->willReturn($mockUser);

        $this->request
            ->method('getParams')
            ->willReturn([]);

        $response = $this->controller->initiateSamenwerking(caseId: 'test-id');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testInitiateSamenwerkingReturnsBadRequestWhenGezagMissing()

    /**
     * doorsturen() returns 400 when doelBevoegdGezag is missing.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V09
     */
    public function testDoorstuurenReturnsBadRequestWhenDoelMissing(): void
    {
        $mockUser = $this->createMock(\OCP\IUser::class);
        $this->userSession
            ->method('getUser')
            ->willReturn($mockUser);

        $this->request
            ->method('getParams')
            ->willReturn([]);

        $response = $this->controller->doorsturen(caseId: 'test-id');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testDoorstuurenReturnsBadRequestWhenDoelMissing()
}//end class
