<?php

/**
 * BrkController Unit Tests
 *
 * Tests for the BRK lookup HTTP surface: 400 on missing/malformed
 * parameters, 401 on no session, and graceful 200 passthrough of the
 * adapter's own `lookupStatus` (including `LOOKUP_DEFERRED` when
 * dormant) rather than an HTTP error. Mirrors `BagControllerTest`.
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

use OCA\Procest\Controller\BrkController;
use OCA\Procest\Service\External\Brk\BrkAdapterInterface;
use OCA\Procest\Service\External\Brk\BrkLookupResult;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Controller\BrkController
 *
 * @uses \OCA\Procest\Service\External\Brk\BrkLookupResult
 */
class BrkControllerTest extends TestCase {

	/**
	 * @var BrkAdapterInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private BrkAdapterInterface $brkAdapter;

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
	 * @var BrkController
	 */
	private BrkController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->brkAdapter = $this->createMock(BrkAdapterInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test-user');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new BrkController(
			appName: 'procest',
			request: $this->request,
			brkAdapter: $this->brkAdapter,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * parcel: 401 when not authenticated.
	 *
	 * @return void
	 */
	public function testParcelReturns401WhenNotAuthenticated(): void {
		$unauthSession = $this->createMock(IUserSession::class);
		$unauthSession->method('getUser')->willReturn(null);

		$controller = new BrkController(
			appName: 'procest',
			request: $this->request,
			brkAdapter: $this->brkAdapter,
			userSession: $unauthSession,
			logger: $this->logger,
		);

		$response = $controller->parcel();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testParcelReturns401WhenNotAuthenticated()

	/**
	 * parcel: 400 when required params are missing.
	 *
	 * @return void
	 */
	public function testParcelReturns400WhenParamsMissing(): void {
		$this->request->method('getParam')->willReturn('');

		$response = $this->controller->parcel();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testParcelReturns400WhenParamsMissing()

	/**
	 * parcel: dormant adapter response is passed through as 200, not an
	 * HTTP error.
	 *
	 * @return void
	 */
	public function testParcelReturns200WithLookupDeferredWhenDormant(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'kadastraleGemeenteCode' => 'VBSTD',
					'sectie' => 'A',
					'perceelnummer' => '1234',
					default => $default,
				};
			}
		);

		$this->brkAdapter->method('lookupByKadastraleAanduiding')->willReturn(
			new BrkLookupResult(
				lookupStatus: 'LOOKUP_DEFERRED',
				parcel: [],
				dormant: true,
				extras: ['reason' => 'no-outbound-connector-bound'],
			)
		);

		$response = $this->controller->parcel();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('LOOKUP_DEFERRED', $data['lookupStatus']);
		$this->assertTrue($data['dormant']);
	}//end testParcelReturns200WithLookupDeferredWhenDormant()

	/**
	 * parcel: a FOUND result is passed through with its normalized
	 * envelope.
	 *
	 * @return void
	 */
	public function testParcelReturnsFoundEnvelope(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'kadastraleGemeenteCode' => 'VBSTD',
					'sectie' => 'A',
					'perceelnummer' => '1234',
					default => $default,
				};
			}
		);

		$this->brkAdapter->method('lookupByKadastraleAanduiding')->willReturn(
			new BrkLookupResult(
				lookupStatus: 'FOUND',
				parcel: ['sectie' => 'A', 'perceelnummer' => 1234],
				dormant: false,
				extras: ['tier' => 'test', 'count' => 1],
			)
		);

		$response = $this->controller->parcel();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('FOUND', $data['lookupStatus']);
		$this->assertSame('A', $data['parcel']['sectie']);
	}//end testParcelReturnsFoundEnvelope()

	/**
	 * parcel: an unexpected exception from the adapter maps to a 500,
	 * never leaks the raw exception message.
	 *
	 * @return void
	 */
	public function testParcelReturns500OnUnexpectedException(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'kadastraleGemeenteCode' => 'VBSTD',
					'sectie' => 'A',
					'perceelnummer' => '1234',
					default => $default,
				};
			}
		);

		$this->brkAdapter->method('lookupByKadastraleAanduiding')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->parcel();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayNotHasKey('boom', $response->getData());
	}//end testParcelReturns500OnUnexpectedException()

	/**
	 * object: 401 when not authenticated.
	 *
	 * @return void
	 */
	public function testObjectReturns401WhenNotAuthenticated(): void {
		$unauthSession = $this->createMock(IUserSession::class);
		$unauthSession->method('getUser')->willReturn(null);

		$controller = new BrkController(
			appName: 'procest',
			request: $this->request,
			brkAdapter: $this->brkAdapter,
			userSession: $unauthSession,
			logger: $this->logger,
		);

		$response = $controller->object('10280123450000');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testObjectReturns401WhenNotAuthenticated()

	/**
	 * object: a NOT_FOUND result is passed through as 200.
	 *
	 * @return void
	 */
	public function testObjectReturns200WithNotFound(): void {
		$this->brkAdapter->method('lookupObject')->willReturn(
			new BrkLookupResult(lookupStatus: 'NOT_FOUND', parcel: [], dormant: false)
		);

		$response = $this->controller->object('00000000000000');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('NOT_FOUND', $response->getData()['lookupStatus']);
	}//end testObjectReturns200WithNotFound()

	/**
	 * object: delegates to the adapter with the right id.
	 *
	 * @return void
	 */
	public function testObjectDelegatesWithCorrectId(): void {
		$this->brkAdapter->expects($this->once())
			->method('lookupObject')
			->with('10280123450000')
			->willReturn(new BrkLookupResult(lookupStatus: 'FOUND', parcel: ['sectie' => 'A'], dormant: false));

		$response = $this->controller->object('10280123450000');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testObjectDelegatesWithCorrectId()
}//end class
