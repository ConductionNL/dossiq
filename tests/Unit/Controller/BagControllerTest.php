<?php

/**
 * BagController Unit Tests
 *
 * Tests for the BAG lookup HTTP surface: 400 on missing/malformed
 * parameters, 401 on no session, and graceful 200 passthrough of the
 * adapter's own `lookupStatus` (including `LOOKUP_DEFERRED` when
 * dormant) rather than an HTTP error.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\BagController;
use OCA\Procest\Service\External\Bag\BagAdapterInterface;
use OCA\Procest\Service\External\Bag\BagLookupResult;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Controller\BagController
 */
class BagControllerTest extends TestCase
{

    /**
     * @var BagAdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private BagAdapterInterface $bagAdapter;

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
     * @var BagController
     */
    private BagController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->bagAdapter  = $this->createMock(BagAdapterInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->request     = $this->createMock(IRequest::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new BagController(
            appName: 'procest',
            request: $this->request,
            bagAdapter: $this->bagAdapter,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * address: 401 when not authenticated.
     *
     * @return void
     */
    public function testAddressReturns401WhenNotAuthenticated(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new BagController(
            appName: 'procest',
            request: $this->request,
            bagAdapter: $this->bagAdapter,
            userSession: $unauthSession,
            logger: $this->logger,
        );

        $response = $controller->address();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testAddressReturns401WhenNotAuthenticated()

    /**
     * address: 400 when postcode/huisnummer are missing.
     *
     * @return void
     */
    public function testAddressReturns400WhenParamsMissing(): void
    {
        $this->request->method('getParam')->willReturn('');

        $response = $this->controller->address();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testAddressReturns400WhenParamsMissing()

    /**
     * address: dormant adapter response is passed through as 200, not an
     * HTTP error.
     *
     * @return void
     */
    public function testAddressReturns200WithLookupDeferredWhenDormant(): void
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

        $this->bagAdapter->method('lookupAddress')->willReturn(
            new BagLookupResult(
                lookupStatus: 'LOOKUP_DEFERRED',
                address: [],
                dormant: true,
                extras: ['reason' => 'no-outbound-connector-bound'],
            )
        );

        $response = $this->controller->address();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('LOOKUP_DEFERRED', $data['lookupStatus']);
        $this->assertTrue($data['dormant']);
    }//end testAddressReturns200WithLookupDeferredWhenDormant()

    /**
     * address: a FOUND result is passed through with its normalized
     * envelope.
     *
     * @return void
     */
    public function testAddressReturnsFoundEnvelope(): void
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

        $this->bagAdapter->method('lookupAddress')->willReturn(
            new BagLookupResult(
                lookupStatus: 'FOUND',
                address: ['street' => 'Voorstraat', 'postcode' => '1234AB'],
                dormant: false,
                extras: ['tier' => 'test', 'count' => 1],
            )
        );

        $response = $this->controller->address();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('FOUND', $data['lookupStatus']);
        $this->assertSame('Voorstraat', $data['address']['street']);
    }//end testAddressReturnsFoundEnvelope()

    /**
     * address: an unexpected exception from the adapter maps to a 500,
     * never leaks the raw exception message.
     *
     * @return void
     */
    public function testAddressReturns500OnUnexpectedException(): void
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

        $this->bagAdapter->method('lookupAddress')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->address();
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertArrayNotHasKey('boom', $response->getData());
    }//end testAddressReturns500OnUnexpectedException()

    /**
     * pand: 401 when not authenticated.
     *
     * @return void
     */
    public function testPandReturns401WhenNotAuthenticated(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new BagController(
            appName: 'procest',
            request: $this->request,
            bagAdapter: $this->bagAdapter,
            userSession: $unauthSession,
            logger: $this->logger,
        );

        $response = $controller->pand('0518100000123456');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testPandReturns401WhenNotAuthenticated()

    /**
     * pand: a NOT_FOUND result is passed through as 200.
     *
     * @return void
     */
    public function testPandReturns200WithNotFound(): void
    {
        $this->bagAdapter->method('lookupObject')->willReturn(
            new BagLookupResult(lookupStatus: 'NOT_FOUND', address: [], dormant: false)
        );

        $response = $this->controller->pand('0000000000000000');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('NOT_FOUND', $response->getData()['lookupStatus']);
    }//end testPandReturns200WithNotFound()

    /**
     * verblijfsobject: delegates to the shared object lookup with the
     * right objectType.
     *
     * @return void
     */
    public function testVerblijfsobjectDelegatesWithCorrectType(): void
    {
        $this->bagAdapter->expects($this->once())
            ->method('lookupObject')
            ->with('verblijfsobject', '0518010000123456')
            ->willReturn(new BagLookupResult(lookupStatus: 'FOUND', address: ['street' => 'Kade'], dormant: false));

        $response = $this->controller->verblijfsobject('0518010000123456');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testVerblijfsobjectDelegatesWithCorrectType()
}//end class
