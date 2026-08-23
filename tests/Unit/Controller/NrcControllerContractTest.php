<?php

/**
 * NrcController Wire-Contract Tests
 *
 * Contract coverage for the two ZGW Notificaties endpoints flagged by gate-25:
 * `notificatieCreate` (POST /notificaties) and `patch` (PATCH /{resource}/{uuid}).
 * Both are `@PublicPage` + `@NoCSRFRequired` + `@CORS`, i.e. reachable WITHOUT a
 * Nextcloud session; their only gatekeepers are the ZGW JWT and, for writes, an
 * OAuth-style scope.
 *
 * The contract pinned here:
 *
 *  - both endpoints return the JWT gate's refusal verbatim and run no body —
 *    these routes are anonymous-reachable, so a body that ran before the gate
 *    would serve/accept notification traffic from anyone on the internet;
 *  - `patch` demands the scope `notificaties.publiceren` on the `nrc` API and
 *    answers the standard ZGW `permission_denied` envelope (403) without it.
 *    The scope NAME is asserted: five near-identical delegators in this
 *    controller gate on the same string, and a copy-paste of a neighbouring
 *    scope is the realistic defect;
 *  - `patch` delegates to the `notificaties` API with the PARTIAL flag set to
 *    true. That final boolean is the entire difference between PATCH and PUT —
 *    a copy of `update()` would pass false and silently turn every partial
 *    update into a full replace, wiping unnamed fields while answering 200;
 *  - `notificatieCreate` acknowledges with 201 and echoes the body back with
 *    the internal `_route` key REMOVED — that key is Nextcloud routing detail
 *    and has no place in a ZGW acknowledgement;
 *  - and, asserted deliberately: `notificatieCreate` consults NO scope at all,
 *    unlike every other write in this controller. That is the live behaviour;
 *    the test pins it as a tripwire so adding the scope check is a deliberate,
 *    visible change rather than a silent drift.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\NrcController;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for NrcController.
 *
 * @covers \OCA\Dossiq\Controller\NrcController
 *
 * NrcController extends ZgwController, which composes NormalisesObjectRows, so
 * exercising it necessarily runs code declared on both. CI runs phpunit.xml
 * with beStrictAboutCoverageMetadata="true" and failOnRisky="true", which marks
 * executed-but-unlisted code risky and fails the run.
 *
 * @uses \OCA\Dossiq\Controller\ZgwController
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 */
class NrcControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The ZgwService mock — JWT gate, scope gate and delegate.
	 *
	 * @var ZgwService|MockObject
	 */
	private ZgwService $zgwService;

	/**
	 * The controller under test.
	 *
	 * @var NrcController
	 */
	private NrcController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->zgwService = $this->createMock(ZgwService::class);

		$this->controller = new NrcController(
			appName: 'dossiq',
			request: $this->request,
			zgwService: $this->zgwService,
		);
	}//end setUp()

	/**
	 * Configure the JWT gate to REFUSE.
	 *
	 * @return void
	 */
	private function refuseJwt(): void {
		$this->zgwService->method('validateJwtAuth')->willReturn(
			new JSONResponse(
				data: ['detail' => 'JWT ontbreekt of is ongeldig'],
				statusCode: Http::STATUS_UNAUTHORIZED
			)
		);
	}//end refuseJwt()

	/**
	 * Configure the JWT gate to ACCEPT.
	 *
	 * @return void
	 */
	private function acceptJwt(): void {
		$this->zgwService->method('validateJwtAuth')->willReturn(null);
	}//end acceptJwt()

	/**
	 * `notificatieCreate` is anonymous-reachable, so an invalid JWT must stop
	 * it before the body: the gate's refusal is returned verbatim.
	 *
	 * @return void
	 */
	public function testNotificatieCreateReturnsTheJwtRefusalVerbatim(): void {
		$this->refuseJwt();
		$this->request->expects($this->never())->method('getParams');

		$response = $this->controller->notificatieCreate();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['detail' => 'JWT ontbreekt of is ongeldig'], $response->getData());
		$this->assertNotSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testNotificatieCreateReturnsTheJwtRefusalVerbatim()

	/**
	 * An accepted notification is acknowledged with 201 and the body echoed
	 * back — minus the internal `_route` routing key.
	 *
	 * @return void
	 */
	public function testNotificatieCreateAcknowledgesWith201AndStripsTheInternalRouteKey(): void {
		$this->acceptJwt();
		$this->request->method('getParams')->willReturn([
			'kanaal' => 'zaken',
			'hoofdObject' => 'https://example.test/zaken/1',
			'actie' => 'create',
			'_route' => 'dossiq.nrc.notificatieCreate',
		]);

		$response = $this->controller->notificatieCreate();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertArrayNotHasKey(
			'_route',
			$response->getData(),
			'the Nextcloud route name must not leak into a ZGW acknowledgement'
		);
		$this->assertSame(
			[
				'kanaal' => 'zaken',
				'hoofdObject' => 'https://example.test/zaken/1',
				'actie' => 'create',
			],
			$response->getData()
		);
	}//end testNotificatieCreateAcknowledgesWith201AndStripsTheInternalRouteKey()

	/**
	 * `notificatieCreate` gates on the JWT ALONE — it consults no ZGW scope,
	 * unlike every other write in this controller.
	 *
	 * Pinned as a tripwire: this is the live behaviour, and adding the
	 * `notificaties.publiceren` check (bringing it in line with create/update/
	 * patch/destroy) must be a deliberate change, not a silent one.
	 *
	 * @return void
	 */
	public function testNotificatieCreateCurrentlyConsultsNoZgwScope(): void {
		$this->acceptJwt();
		$this->request->method('getParams')->willReturn(['kanaal' => 'zaken']);
		$this->zgwService->expects($this->never())->method('consumerHasScope');

		$response = $this->controller->notificatieCreate();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testNotificatieCreateCurrentlyConsultsNoZgwScope()

	/**
	 * `patch` returns the JWT gate's refusal verbatim and never reaches the
	 * scope check or the delegate.
	 *
	 * @return void
	 */
	public function testPatchReturnsTheJwtRefusalBeforeCheckingAnyScope(): void {
		$this->refuseJwt();
		$this->zgwService->expects($this->never())->method('consumerHasScope');
		$this->zgwService->expects($this->never())->method('handleUpdate');

		$response = $this->controller->patch(resource: 'abonnement', uuid: 'ab-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['detail' => 'JWT ontbreekt of is ongeldig'], $response->getData());
	}//end testPatchReturnsTheJwtRefusalBeforeCheckingAnyScope()

	/**
	 * A consumer without `notificaties.publiceren` on the `nrc` API is refused
	 * with the standard ZGW permission-denied envelope, and nothing is written.
	 *
	 * The scope name is asserted because five delegators in this controller gate
	 * on the same literal and a wrong one would still return a plausible 403 —
	 * or, worse, admit a consumer holding a neighbouring scope.
	 *
	 * @return void
	 */
	public function testPatchDemandsTheNotificatiesPubliceranScopeAndRefusesWithout(): void {
		$this->acceptJwt();

		$this->zgwService->expects($this->once())
			->method('consumerHasScope')
			->with($this->request, 'nrc', 'notificaties.publiceren')
			->willReturn(false);
		$this->zgwService->expects($this->never())->method('handleUpdate');

		$response = $this->controller->patch(resource: 'abonnement', uuid: 'ab-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('permission_denied', $response->getData()['code']);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getData()['status']);
		$this->assertStringContainsString('notificaties.publiceren', $response->getData()['detail']);
	}//end testPatchDemandsTheNotificatiesPubliceranScopeAndRefusesWithout()

	/**
	 * An authorized patch delegates to the `notificaties` API for the addressed
	 * resource/uuid with the PARTIAL flag set to TRUE.
	 *
	 * That final boolean is the whole difference between PATCH and PUT: passing
	 * false would replace the abonnement wholesale — dropping every field the
	 * caller did not name — while still answering 200.
	 *
	 * @return void
	 */
	public function testPatchDelegatesAsAPartialUpdateNotAFullReplace(): void {
		$this->acceptJwt();
		$this->zgwService->method('consumerHasScope')->willReturn(true);

		$expected = new JSONResponse(data: ['url' => 'https://example.test/abonnementen/ab-1']);
		$this->zgwService->expects($this->once())
			->method('handleUpdate')
			->with($this->request, 'notificaties', 'abonnement', 'ab-1', true)
			->willReturn($expected);

		$response = $this->controller->patch(resource: 'abonnement', uuid: 'ab-1');

		$this->assertSame($expected, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testPatchDelegatesAsAPartialUpdateNotAFullReplace()

	/**
	 * The resource segment from the URL is the one addressed — the delegator
	 * must not hard-code a single resource name.
	 *
	 * @return void
	 */
	public function testPatchAddressesTheResourceNamedInTheUrl(): void {
		$this->acceptJwt();
		$this->zgwService->method('consumerHasScope')->willReturn(true);

		$this->zgwService->expects($this->once())
			->method('handleUpdate')
			->with($this->request, 'notificaties', 'kanaal', 'kan-9', true)
			->willReturn(new JSONResponse(data: ['naam' => 'zaken']));

		$response = $this->controller->patch(resource: 'kanaal', uuid: 'kan-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['naam' => 'zaken'], $response->getData());
	}//end testPatchAddressesTheResourceNamedInTheUrl()
}//end class
