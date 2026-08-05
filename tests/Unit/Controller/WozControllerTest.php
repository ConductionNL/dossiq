<?php

/**
 * WozController Unit Tests
 *
 * Tests for the WOZ lookup HTTP surface: 400 on missing/malformed
 * parameters, 401 on no session, and graceful 200 passthrough of the
 * adapter's own `lookupStatus` (including `LOOKUP_DEFERRED` when
 * dormant) rather than an HTTP error. Mirrors `BagControllerTest` /
 * `BrkControllerTest`.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\WozController;
use OCA\Procest\Service\External\Woz\WozAdapterInterface;
use OCA\Procest\Service\External\Woz\WozLookupResult;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Controller\WozController
 *
 * @uses \OCA\Procest\Service\External\Woz\WozLookupResult
 */
class WozControllerTest extends TestCase
{

    /**
     * @var WozAdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private WozAdapterInterface $wozAdapter;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var WozController
     */
    private WozController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->wozAdapter   = $this->createMock(WozAdapterInterface::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->request      = $this->createMock(IRequest::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new WozController(
            appName: 'procest',
            request: $this->request,
            wozAdapter: $this->wozAdapter,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * value: 401 when not authenticated.
     *
     * @return void
     */
    public function testValueReturns401WhenNotAuthenticated(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new WozController(
            appName: 'procest',
            request: $this->request,
            wozAdapter: $this->wozAdapter,
            userSession: $unauthSession,
            logger: $this->logger,
        );

        $response = $controller->value();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testValueReturns401WhenNotAuthenticated()

    /**
     * value: 400 when neither nummeraanduidingId nor postcode+huisnummer
     * are supplied.
     *
     * @return void
     */
    public function testValueReturns400WhenParamsMissing(): void
    {
        $this->request->method('getParam')->willReturn('');

        $response = $this->controller->value();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testValueReturns400WhenParamsMissing()

    /**
     * value: a nummeraanduidingId query param routes to
     * lookupByNummeraanduiding, not lookupAddress.
     *
     * @return void
     */
    public function testValueRoutesToNummeraanduidingLookupWhenIdSupplied(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return match ($key) {
                    'nummeraanduidingId' => '0518010000123456',
                    default              => $default,
                };
            }
        );

        $this->wozAdapter->expects($this->once())
            ->method('lookupByNummeraanduiding')
            ->with('0518010000123456')
            ->willReturn(new WozLookupResult(lookupStatus: 'FOUND', wozObject: ['wozobjectnummer' => '1'], dormant: false));

        $this->wozAdapter->expects($this->never())->method('lookupAddress');

        $response = $this->controller->value();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testValueRoutesToNummeraanduidingLookupWhenIdSupplied()

    /**
     * value: dormant adapter response is passed through as 200, not an
     * HTTP error.
     *
     * @return void
     */
    public function testValueReturns200WithLookupDeferredWhenDormant(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return match ($key) {
                    'postcode'   => '1234AB',
                    'huisnummer' => '10',
                    default      => $default,
                };
            }
        );

        $this->wozAdapter->method('lookupAddress')->willReturn(
            new WozLookupResult(
                lookupStatus: 'LOOKUP_DEFERRED',
                wozObject: [],
                dormant: true,
                extras: ['reason' => 'no-outbound-connector-bound'],
            )
        );

        $response = $this->controller->value();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('LOOKUP_DEFERRED', $data['lookupStatus']);
        $this->assertTrue($data['dormant']);
    }//end testValueReturns200WithLookupDeferredWhenDormant()

    /**
     * value: a FOUND result is passed through with its normalized
     * envelope.
     *
     * @return void
     */
    public function testValueReturnsFoundEnvelope(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return match ($key) {
                    'postcode'   => '1234AB',
                    'huisnummer' => '10',
                    default      => $default,
                };
            }
        );

        $this->wozAdapter->method('lookupAddress')->willReturn(
            new WozLookupResult(
                lookupStatus: 'FOUND',
                wozObject: ['wozobjectnummer' => '05180000001234', 'waarde' => 385000],
                dormant: false,
                extras: ['tier' => 'test', 'count' => 1],
            )
        );

        $response = $this->controller->value();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('FOUND', $data['lookupStatus']);
        $this->assertSame(385000, $data['wozObject']['waarde']);
    }//end testValueReturnsFoundEnvelope()

    /**
     * value: an unexpected exception from the adapter maps to a 500, never
     * leaks the raw exception message.
     *
     * @return void
     */
    public function testValueReturns500OnUnexpectedException(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return match ($key) {
                    'postcode'   => '1234AB',
                    'huisnummer' => '10',
                    default      => $default,
                };
            }
        );

        $this->wozAdapter->method('lookupAddress')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->value();
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertArrayNotHasKey('boom', $response->getData());
    }//end testValueReturns500OnUnexpectedException()

    /**
     * object: 401 when not authenticated.
     *
     * @return void
     */
    public function testObjectReturns401WhenNotAuthenticated(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new WozController(
            appName: 'procest',
            request: $this->request,
            wozAdapter: $this->wozAdapter,
            userSession: $unauthSession,
            logger: $this->logger,
        );

        $response = $controller->object('05180000001234');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testObjectReturns401WhenNotAuthenticated()

    /**
     * object: a NOT_FOUND result is passed through as 200.
     *
     * @return void
     */
    public function testObjectReturns200WithNotFound(): void
    {
        $this->wozAdapter->method('lookupByWozObjectNummer')->willReturn(
            new WozLookupResult(lookupStatus: 'NOT_FOUND', wozObject: [], dormant: false)
        );

        $response = $this->controller->object('00000000000000');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('NOT_FOUND', $response->getData()['lookupStatus']);
    }//end testObjectReturns200WithNotFound()

    /**
     * object: delegates to the adapter with the right wozobjectnummer.
     *
     * @return void
     */
    public function testObjectDelegatesWithCorrectWozObjectNummer(): void
    {
        $this->wozAdapter->expects($this->once())
            ->method('lookupByWozObjectNummer')
            ->with('05180000001234')
            ->willReturn(new WozLookupResult(lookupStatus: 'FOUND', wozObject: ['wozobjectnummer' => '05180000001234'], dormant: false));

        $response = $this->controller->object('05180000001234');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testObjectDelegatesWithCorrectWozObjectNummer()
}//end class
