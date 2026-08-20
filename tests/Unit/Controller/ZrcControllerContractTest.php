<?php

/**
 * ZrcController Wire-Contract Tests
 *
 * Contract coverage for the eight publicly-reachable ZRC endpoints that had no
 * automated proof of their wire behaviour (gate-25). Every endpoint in this
 * controller is annotated `@PublicPage`, i.e. it is reachable WITHOUT a
 * Nextcloud session, and its only gatekeeper is the ZGW JWT that
 * `ZgwService::validateJwtAuth()` inspects plus a per-operation OAuth-style
 * scope. Those two facts are the contract these tests pin:
 *
 *  - an unauthenticated caller gets the JWT error verbatim, never the payload;
 *  - a caller WITHOUT the operation's scope gets 403, and the scope demanded is
 *    the specific one the ZGW standard assigns to that operation
 *    (`zaken.bijwerken` for writes to a sub-resource, `zaken.verwijderen` for a
 *    delete) — a copy-paste of the wrong scope name is the realistic defect in
 *    six near-identical delegators;
 *  - the delegating endpoints address the `zaakeigenschappen` resource of the
 *    `zaken` API, not some neighbouring resource;
 *  - `zoek` answers 201 rather than the 200 its own delegate returns, as the
 *    ZGW specification requires for the search operation.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ZrcController;
use OCA\Procest\Service\CaseRelationService;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for ZrcController.
 *
 * @covers \OCA\Procest\Controller\ZrcController
 *
 * The ZGW controllers inherit from ZgwController, which in turn composes the
 * NormalisesObjectRows trait, so exercising this controller necessarily runs
 * code declared on both. CI runs phpunit.xml with
 * beStrictAboutCoverageMetadata="true" and failOnRisky="true", which marks
 * executed-but-unlisted code risky and fails the run — so both are declared as
 * used rather than covered.
 *
 * @uses \OCA\Procest\Controller\ZgwController
 * @uses \OCA\Procest\Support\NormalisesObjectRows
 */
class ZrcControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The ZgwService mock — the controller's only gatekeeper and delegate.
	 *
	 * @var ZgwService|MockObject
	 */
	private ZgwService $zgwService;

	/**
	 * The IL10N mock used by the permission-denied response.
	 *
	 * @var IL10N|MockObject
	 */
	private IL10N $l10n;

	/**
	 * The CaseRelationService mock.
	 *
	 * @var CaseRelationService|MockObject
	 */
	private CaseRelationService $caseRelationService;

	/**
	 * The controller under test.
	 *
	 * @var ZrcController
	 */
	private ZrcController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->zgwService = $this->createMock(ZgwService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->caseRelationService = $this->createMock(CaseRelationService::class);

		$this->l10n->method('t')->willReturnArgument(0);

		$this->controller = new ZrcController(
			appName: 'procest',
			request: $this->request,
			zgwService: $this->zgwService,
			l10n: $this->l10n,
			caseRelationService: $this->caseRelationService,
		);
	}//end setUp()

	/**
	 * Configure the JWT gate to REFUSE with the supplied status.
	 *
	 * @param int $status The HTTP status the JWT gate answers with.
	 *
	 * @return void
	 */
	private function refuseJwt(int $status = Http::STATUS_UNAUTHORIZED): void {
		$this->zgwService->method('validateJwtAuth')->willReturn(
			new JSONResponse(data: ['detail' => 'JWT missing'], statusCode: $status)
		);
	}//end refuseJwt()

	/**
	 * Configure the JWT gate to ACCEPT (validateJwtAuth returns null).
	 *
	 * @return void
	 */
	private function acceptJwt(): void {
		$this->zgwService->method('validateJwtAuth')->willReturn(null);
	}//end acceptJwt()

	/**
	 * Every anonymous-reachable endpoint must return the JWT gate's refusal.
	 *
	 * This is the single most important property of the controller: all eight
	 * routes are `@PublicPage`, so if any of them ran its body before consulting
	 * `validateJwtAuth()` it would serve zaak data to an unauthenticated caller.
	 *
	 * @return void
	 */
	public function testAllZaakSubResourceEndpointsRefuseAnUnauthenticatedCaller(): void {
		$this->refuseJwt();
		$this->zgwService->method('resolvePathUuid')->willReturnArgument(1);

		$responses = [
			'zaakeigenschappenIndex' => $this->controller->zaakeigenschappenIndex(zaakUuid: 'zaak-1'),
			'zaakeigenschappenCreate' => $this->controller->zaakeigenschappenCreate(zaakUuid: 'zaak-1'),
			'zaakeigenschappenShow' => $this->controller->zaakeigenschappenShow(zaakUuid: 'zaak-1', uuid: 'e-1'),
			'zaakeigenschappenUpdate' => $this->controller->zaakeigenschappenUpdate(zaakUuid: 'zaak-1', uuid: 'e-1'),
			'zaakeigenschappenPatch' => $this->controller->zaakeigenschappenPatch(zaakUuid: 'zaak-1', uuid: 'e-1'),
			'zaakeigenschappenDestroy' => $this->controller->zaakeigenschappenDestroy(zaakUuid: 'zaak-1', uuid: 'e-1'),
			'zaakbesluitenIndex' => $this->controller->zaakbesluitenIndex(zaakUuid: 'zaak-1'),
			'zoek' => $this->controller->zoek(),
		];

		foreach ($responses as $endpoint => $response) {
			// `zoek` re-wraps its delegate's payload and is documented to answer
			// 201 — but it must not turn a REFUSAL into a success payload, so
			// its refusal carries the delegate's error body through.
			if ($endpoint === 'zoek') {
				$this->assertSame(
					['detail' => 'JWT missing'],
					$response->getData(),
					'zoek must carry the JWT refusal body through, not an empty result set'
				);
				continue;
			}

			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$endpoint . ' must return the JWT gate refusal, not its payload'
			);
		}
	}//end testAllZaakSubResourceEndpointsRefuseAnUnauthenticatedCaller()

	/**
	 * zaakeigenschappenIndex delegates to the zaken API's zaakeigenschappen
	 * resource and returns the handler's response untouched.
	 *
	 * @return void
	 */
	public function testZaakeigenschappenIndexDelegatesToTheZaakeigenschappenResource(): void {
		$this->acceptJwt();
		$expected = new JSONResponse(data: [['url' => 'https://example.test/e-1']]);

		$this->zgwService->expects($this->once())
			->method('handleIndex')
			->with($this->request, 'zaken', 'zaakeigenschappen')
			->willReturn($expected);

		$response = $this->controller->zaakeigenschappenIndex(zaakUuid: 'zaak-1');

		$this->assertSame($expected, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testZaakeigenschappenIndexDelegatesToTheZaakeigenschappenResource()

	/**
	 * zaakeigenschappenShow delegates with the sub-resource uuid, NOT the zaak
	 * uuid — transposing the two is the realistic defect on a two-uuid route.
	 *
	 * @return void
	 */
	public function testZaakeigenschappenShowDelegatesWithTheSubResourceUuid(): void {
		$this->acceptJwt();
		$expected = new JSONResponse(data: ['url' => 'https://example.test/e-1']);

		$this->zgwService->expects($this->once())
			->method('handleShow')
			->with($this->request, 'zaken', 'zaakeigenschappen', 'eigenschap-9')
			->willReturn($expected);

		$response = $this->controller->zaakeigenschappenShow(zaakUuid: 'zaak-1', uuid: 'eigenschap-9');

		$this->assertSame($expected, $response);
	}//end testZaakeigenschappenShowDelegatesWithTheSubResourceUuid()

	/**
	 * Creating a zaakeigenschap demands `zaken.bijwerken`, not
	 * `zaken.aanmaken` — the latter is reserved for creating a zaak itself.
	 *
	 * @return void
	 */
	public function testZaakeigenschappenCreateDemandsTheBijwerkenScopeAndRefusesWithout(): void {
		$this->acceptJwt();

		$this->zgwService->expects($this->once())
			->method('consumerHasScope')
			->with($this->request, 'zrc', 'zaken.bijwerken')
			->willReturn(false);

		$response = $this->controller->zaakeigenschappenCreate(zaakUuid: 'zaak-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('permission_denied', $response->getData()['code']);
	}//end testZaakeigenschappenCreateDemandsTheBijwerkenScopeAndRefusesWithout()

	/**
	 * Updating a zaakeigenschap demands `zaken.bijwerken`.
	 *
	 * @return void
	 */
	public function testZaakeigenschappenUpdateDemandsTheBijwerkenScopeAndRefusesWithout(): void {
		$this->acceptJwt();
		$this->zgwService->method('resolvePathUuid')->willReturnArgument(1);

		$this->zgwService->expects($this->once())
			->method('consumerHasScope')
			->with($this->request, 'zrc', 'zaken.bijwerken')
			->willReturn(false);

		$response = $this->controller->zaakeigenschappenUpdate(zaakUuid: 'zaak-1', uuid: 'e-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('permission_denied', $response->getData()['code']);
	}//end testZaakeigenschappenUpdateDemandsTheBijwerkenScopeAndRefusesWithout()

	/**
	 * Patching a zaakeigenschap demands `zaken.bijwerken`.
	 *
	 * @return void
	 */
	public function testZaakeigenschappenPatchDemandsTheBijwerkenScopeAndRefusesWithout(): void {
		$this->acceptJwt();
		$this->zgwService->method('resolvePathUuid')->willReturnArgument(1);

		$this->zgwService->expects($this->once())
			->method('consumerHasScope')
			->with($this->request, 'zrc', 'zaken.bijwerken')
			->willReturn(false);

		$response = $this->controller->zaakeigenschappenPatch(zaakUuid: 'zaak-1', uuid: 'e-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('permission_denied', $response->getData()['code']);
	}//end testZaakeigenschappenPatchDemandsTheBijwerkenScopeAndRefusesWithout()

	/**
	 * Deleting a zaakeigenschap demands `zaken.verwijderen` — a DIFFERENT scope
	 * from the write operations above. A delete accepted on a write scope is a
	 * privilege escalation, so the scope name is asserted, not just the 403.
	 *
	 * @return void
	 */
	public function testZaakeigenschappenDestroyDemandsTheVerwijderenScopeAndRefusesWithout(): void {
		$this->acceptJwt();

		$this->zgwService->expects($this->once())
			->method('consumerHasScope')
			->with($this->request, 'zrc', 'zaken.verwijderen')
			->willReturn(false);

		$response = $this->controller->zaakeigenschappenDestroy(zaakUuid: 'zaak-1', uuid: 'e-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('permission_denied', $response->getData()['code']);
	}//end testZaakeigenschappenDestroyDemandsTheVerwijderenScopeAndRefusesWithout()

	/**
	 * zaakbesluitenIndex answers 503 when OpenRegister is not wired up, rather
	 * than throwing or serving an empty list that a client cannot distinguish
	 * from "this zaak has no besluiten".
	 *
	 * @return void
	 */
	public function testZaakbesluitenIndexReturns503WhenTheObjectStoreIsUnavailable(): void {
		$this->acceptJwt();
		$this->zgwService->method('getObjectService')->willReturn(null);
		$this->zgwService->method('unavailableResponse')->willReturn(
			new JSONResponse(
				data: ['detail' => 'OpenRegister is not available'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			)
		);

		$response = $this->controller->zaakbesluitenIndex(zaakUuid: 'zaak-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testZaakbesluitenIndexReturns503WhenTheObjectStoreIsUnavailable()

	/**
	 * zaakbesluitenIndex answers 404 when the besluiten mapping is absent —
	 * distinct from the 503 above, because an unconfigured mapping is a
	 * deployment fact a client can act on.
	 *
	 * @return void
	 */
	public function testZaakbesluitenIndexReturns404WhenTheBesluitMappingIsMissing(): void {
		$this->acceptJwt();
		$this->zgwService->method('getObjectService')->willReturn(new \stdClass());
		$this->zgwService->method('loadMappingConfig')->willReturn(null);

		$response = $this->controller->zaakbesluitenIndex(zaakUuid: 'zaak-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['detail' => 'Besluit mapping not configured'], $response->getData());
	}//end testZaakbesluitenIndexReturns404WhenTheBesluitMappingIsMissing()

	/**
	 * `POST /zaken/_zoek` returns 201 Created, not the 200 its own delegate
	 * answers with, and carries the delegate's result set unchanged.
	 *
	 * @return void
	 */
	public function testZoekReturns201WithTheSearchResultsOfTheZakenResource(): void {
		$this->acceptJwt();
		$results = [['identificatie' => 'ZAAK-2026-0001']];

		$this->zgwService->expects($this->once())
			->method('handleIndex')
			->with($this->request, 'zaken', 'zaken')
			->willReturn(new JSONResponse(data: $results));

		$response = $this->controller->zoek();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($results, $response->getData());
	}//end testZoekReturns201WithTheSearchResultsOfTheZakenResource()
}//end class
