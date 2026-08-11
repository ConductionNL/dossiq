<?php

/**
 * SubsidieController::createTussenrapportage() unit tests.
 *
 * `TussenrapportageService::createExpected()` has been implemented since
 * subsidieverlening-keten and no caller ever reached it: the controller
 * injected the service and exposed only `beoordelen`, so an interim report
 * could be assessed but never scheduled. These tests pin the endpoint that
 * closes that gap.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\SubsidieController;
use OCA\Procest\Service\Subsidie\BeschikkingService;
use OCA\Procest\Service\Subsidie\SubsidieService;
use OCA\Procest\Service\Subsidie\TussenrapportageService;
use OCA\Procest\Service\Subsidie\VaststellingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SubsidieController::createTussenrapportage().
 *
 * @covers \OCA\Procest\Controller\SubsidieController
 */
final class SubsidieControllerCreateTussenrapportageTest extends TestCase
{

    /**
     * Inbound request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * Interim-report service under the endpoint.
     *
     * @var TussenrapportageService|\PHPUnit\Framework\MockObject\MockObject
     */
    private TussenrapportageService $tussenrapportage;

    /**
     * Current user session.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * The controller under test.
     *
     * @var SubsidieController
     */
    private SubsidieController $controller;


    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request          = $this->createMock(IRequest::class);
        $this->tussenrapportage = $this->createMock(TussenrapportageService::class);
        $this->userSession      = $this->createMock(IUserSession::class);

        $this->controller = new SubsidieController(
            request: $this->request,
            subsidieService: $this->createMock(SubsidieService::class),
            beschikkingService: $this->createMock(BeschikkingService::class),
            tussenrapportage: $this->tussenrapportage,
            vaststellingService: $this->createMock(VaststellingService::class),
            userSession: $this->userSession,
        );
    }//end setUp()


    /**
     * Mark the session as authenticated for user `alice`.
     *
     * @return void
     */
    private function authenticate(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }//end authenticate()


    /**
     * Feed the request parameter bag.
     *
     * @param array<string, mixed> $params The request parameters.
     *
     * @return void
     */
    private function withParams(array $params): void
    {
        $this->request->method('getParams')->willReturn($params);
    }//end withParams()


    /**
     * An anonymous caller is rejected before the service is consulted.
     *
     * @return void
     */
    public function testAnonymousCallerIsRejectedAndServiceNeverRuns(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->tussenrapportage->expects($this->never())->method('createExpected');

        $response = $this->controller->createTussenrapportage(uitvoeringId: 'U/2026/1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
    }//end testAnonymousCallerIsRejectedAndServiceNeverRuns()


    /**
     * The report is created and returned with 201.
     *
     * @return void
     */
    public function testCreatesExpectedReportAndReturnsCreated(): void
    {
        $this->authenticate();
        $this->withParams(
            [
                'periodeStart' => '2026-01-01',
                'periodeEind'  => '2026-06-30',
                '_route'       => 'procest.subsidie.createTussenrapportage',
                'uitvoeringId' => 'U/2026/1',
            ]
        );

        $created = [
            'id'                 => 'TR/1',
            'subsidieuitvoering' => 'U/2026/1',
            'status'             => 'verwacht',
        ];

        $this->tussenrapportage->expects($this->once())
            ->method('createExpected')
            ->with(
                'U/2026/1',
                [
                    'periodeStart' => '2026-01-01',
                    'periodeEind'  => '2026-06-30',
                ]
            )
            ->willReturn($created);

        $response = $this->controller->createTussenrapportage(uitvoeringId: 'U/2026/1');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame($created, $response->getData());
    }//end testCreatesExpectedReportAndReturnsCreated()


    /**
     * Routing parameters never leak into the persisted payload.
     *
     * `uitvoeringId` arrives on the URL and is passed as its own argument; if it
     * also reached the payload the record would carry a stray property.
     *
     * @return void
     */
    public function testRoutingParametersAreStrippedFromThePayload(): void
    {
        $this->authenticate();
        $this->withParams(
            [
                'uitvoeringId' => 'U/2026/2',
                '_route'       => 'procest.subsidie.createTussenrapportage',
                'frequentie'   => 'halfjaarlijks',
            ]
        );

        $this->tussenrapportage->expects($this->once())
            ->method('createExpected')
            ->with('U/2026/2', ['frequentie' => 'halfjaarlijks'])
            ->willReturn(['status' => 'verwacht']);

        $response = $this->controller->createTussenrapportage(uitvoeringId: 'U/2026/2');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }//end testRoutingParametersAreStrippedFromThePayload()


    /**
     * A persistence failure becomes a 400, not a 500.
     *
     * @return void
     */
    public function testBadRequestExceptionBecomesBadRequest(): void
    {
        $this->authenticate();
        $this->withParams([]);

        $this->tussenrapportage->method('createExpected')
            ->willThrowException(new OCSBadRequestException('Kon tussenrapportage niet aanmaken'));

        $response = $this->controller->createTussenrapportage(uitvoeringId: 'U/2026/3');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'Kon tussenrapportage niet aanmaken'], $response->getData());
    }//end testBadRequestExceptionBecomesBadRequest()
}//end class
