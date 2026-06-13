<?php

/**
 * CaseGeoController Unit Tests
 *
 * Tests for the cases-on-map data endpoint, focusing on the per-object access
 * guard (no IDOR) and graceful degradation (gis-integration spec).
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
 * @spec openspec/specs/gis-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\CaseGeoController;
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\GeoService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CaseGeoController.
 *
 * @covers \OCA\Procest\Controller\CaseGeoController
 */
class CaseGeoControllerTest extends TestCase
{

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * @var GeoService|\PHPUnit\Framework\MockObject\MockObject
     */
    private GeoService $geoService;

    /**
     * @var CaseSharingService|\PHPUnit\Framework\MockObject\MockObject
     */
    private CaseSharingService $caseSharingService;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * @var CaseGeoController
     */
    private CaseGeoController $controller;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request            = $this->createMock(originalClassName: IRequest::class);
        $this->geoService         = $this->createMock(originalClassName: GeoService::class);
        $this->caseSharingService = $this->createMock(originalClassName: CaseSharingService::class);
        $this->userSession        = $this->createMock(originalClassName: IUserSession::class);

        $this->controller = new CaseGeoController(
            request: $this->request,
            geoService: $this->geoService,
            caseSharingService: $this->caseSharingService,
            userSession: $this->userSession,
            logger: $this->createMock(originalClassName: LoggerInterface::class),
        );
    }//end setUp()

    /**
     * An unauthenticated request is rejected with 401.
     *
     * @return void
     */
    public function testGeoRejectsUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->geo();
        $this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
    }//end testGeoRejectsUnauthenticated()

    /**
     * Only the cases the user may read are passed into the service as the
     * readableCaseIds allow-list (no IDOR).
     *
     * @return void
     */
    public function testGeoEnforcesPerObjectAccessGuard(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) {
                return $default;
            }
        );

        // Two candidate cases; alice may only read c1.
        $this->geoService->method('listCaseIds')->willReturn(['c1', 'c2']);
        $this->caseSharingService->method('canUserAccessCase')->willReturnMap(
            [
                ['c1', 'alice', true],
                ['c2', 'alice', false],
            ]
        );

        $this->geoService->expects($this->once())
            ->method('buildCaseGeoCollection')
            ->with($this->callback(static function (array $filters): bool {
                return ($filters['readableCaseIds'] ?? null) === ['c1'];
            }))
            ->willReturn(['type' => 'FeatureCollection', 'features' => [], 'total' => 2, 'filtered' => 0]);

        $response = $this->controller->geo();
        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
    }//end testGeoEnforcesPerObjectAccessGuard()

    /**
     * A service failure degrades to an empty collection (200) rather than 500.
     *
     * @return void
     */
    public function testGeoDegradesGracefullyOnServiceFailure(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $default
        );

        $this->geoService->method('listCaseIds')->willReturn([]);
        $this->geoService->method('buildCaseGeoCollection')
            ->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->geo();
        $data     = $response->getData();

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 'FeatureCollection', actual: $data['type']);
        $this->assertSame(expected: [], actual: $data['features']);
    }//end testGeoDegradesGracefullyOnServiceFailure()
}//end class
