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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\DsoController;
use OCA\Procest\Service\BeschikkingGenerationService;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SamenwerkverzoekService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DsoController.
 *
 * @covers \OCA\Procest\Controller\DsoController
 */
class DsoControllerTest extends TestCase
{

    /**
     * Mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * Mocked DSO case service.
     *
     * @var DsoCaseService|\PHPUnit\Framework\MockObject\MockObject
     */
    private DsoCaseService $dsoCaseService;

    /**
     * Mocked beschikking service.
     *
     * @var BeschikkingGenerationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private BeschikkingGenerationService $beschikkingService;

    /**
     * Mocked samenwerk service.
     *
     * @var SamenwerkverzoekService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SamenwerkverzoekService $samenwerkService;

    /**
     * Mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mocked user session.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * Mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The controller under test.
     *
     * @var DsoController
     */
    private DsoController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->request            = $this->createMock(IRequest::class);
        $this->dsoCaseService     = $this->createMock(DsoCaseService::class);
        $this->beschikkingService = $this->createMock(BeschikkingGenerationService::class);
        $this->samenwerkService   = $this->createMock(SamenwerkverzoekService::class);
        $this->settingsService    = $this->createMock(SettingsService::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->controller = new DsoController(
            appName: 'procest',
            request: $this->request,
            dsoCaseService: $this->dsoCaseService,
            beschikkingGenerationService: $this->beschikkingService,
            samenwerkverzoekService: $this->samenwerkService,
            settingsService: $this->settingsService,
            userSession: $this->userSession,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that dashboard() returns 401 when not authenticated.
     *
     * @return void
     */
    public function testDashboardReturns401WhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->dashboard();

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->assertInstanceOf(JSONResponse::class, $response);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->assertSame(expected: 401, actual: $response->getStatus());

    }//end testDashboardReturns401WhenNotAuthenticated()

    /**
     * Test that dashboard() returns 503 when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testDashboardReturns503WhenOpenRegisterUnavailable(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $user = $this->createMock(IUser::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->userSession->method('getUser')->willReturn($user);

        $this->settingsService->method('getObjectService')->willReturn(null);

        $response = $this->controller->dashboard();

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->assertInstanceOf(JSONResponse::class, $response);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->assertSame(expected: 503, actual: $response->getStatus());

    }//end testDashboardReturns503WhenOpenRegisterUnavailable()

    /**
     * Test that transitionStatus() returns 400 when status is missing.
     *
     * @return void
     */
    public function testTransitionStatusReturns400WhenStatusMissing(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $user = $this->createMock(IUser::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $user->method('getUID')->willReturn('admin');
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->userSession->method('getUser')->willReturn($user);

        $objectService = new class {
            /**
             * Stub getObject for case.
             *
             * @param string $register The register slug
             * @param string $schema   The schema slug
             * @param string $id       The object id
             *
             * @return array<string, mixed>
             */
            public function getObject(string $register, string $schema, string $id): array
            {
                return ['id' => $id, 'assignee' => 'admin', 'status' => 'ingediend'];
            }//end getObject()
        };

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap(
                [
                    ['register', '', 'procest'],
                    ['case_schema', '', 'case'],
                ]
            );

        $this->request->method('getContent')->willReturn('{}');

        $response = $this->controller->transitionStatus(caseId: 'zaak-123');

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->assertInstanceOf(JSONResponse::class, $response);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->assertSame(expected: 400, actual: $response->getStatus());

    }//end testTransitionStatusReturns400WhenStatusMissing()

    /**
     * Test that generateBeschikking() returns 400 when outcome is missing.
     *
     * @return void
     */
    public function testGenerateBeschikkingReturns400WhenOutcomeMissing(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $user = $this->createMock(IUser::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $user->method('getUID')->willReturn('admin');
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->userSession->method('getUser')->willReturn($user);

        $objectService = new class {
            /**
             * Stub getObject for case.
             *
             * @param string $register The register slug
             * @param string $schema   The schema slug
             * @param string $id       The object id
             *
             * @return array<string, mixed>
             */
            public function getObject(string $register, string $schema, string $id): array
            {
                return ['id' => $id, 'assignee' => 'admin'];
            }//end getObject()
        };

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService
            ->method('getConfigValue')
            ->willReturnMap(
                [
                    ['register', '', 'procest'],
                    ['case_schema', '', 'case'],
                ]
            );
        $this->request->method('getContent')->willReturn('{}');

        $response = $this->controller->generateBeschikking(caseId: 'zaak-123');

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->assertInstanceOf(JSONResponse::class, $response);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->assertSame(expected: 400, actual: $response->getStatus());

    }//end testGenerateBeschikkingReturns400WhenOutcomeMissing()
}//end class
