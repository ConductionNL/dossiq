<?php

/**
 * ZtcPublishController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the three ZTC publish endpoints that had no
 * automated proof of their wire behaviour. All three are `@PublicPage`, i.e.
 * reachable WITHOUT a Nextcloud session, and all three are three-line
 * delegators onto one shared `handlePublish()` body — which is exactly why the
 * realistic defect here is a copy-paste: publishing a zaaktype through the
 * besluittype route would flip `isDraft` on the wrong catalogue object, and
 * nothing in the response shape would show it.
 *
 * What is pinned:
 *
 *  - the JWT gate is consulted BEFORE anything is published, and its refusal is
 *    returned verbatim — an unauthenticated caller must not be able to publish
 *    a draft catalogue object;
 *  - each route addresses its OWN resource of the `catalogi` API — `zaaktypen`,
 *    `besluittypen`, `informatieobjecttypen` — asserted by name;
 *  - publishing means writing `isDraft: false` back onto the stored object and
 *    answering 201, not merely reading it;
 *  - an unavailable OpenRegister answers 503 and a missing mapping 404, two
 *    distinct deployment facts a client can act on.
 *
 *  - the catalogi write scope is required on all three routes, like the other
 *    ZTC writes (decision 245);
 *  - a zaaktype passes the CT-02b publish guard before it is saved, and its
 *    refusal reaches the client as a ZGW error (REQ-ZBR-024).
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

use OCA\Dossiq\Controller\ZtcPublishController;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Minimal OpenRegister ObjectService stub for the ZTC publish tests.
 *
 * The real ObjectService is called with NAMED arguments by the controller, so
 * the stub's parameter names must match the real ones exactly — a mock built on
 * \stdClass cannot be called with named arguments at all.
 */
interface ZtcContractObjectServiceStub {
	/**
	 * Find a single object by identifier (real ObjectService::find()).
	 *
	 * @param int|string $id The object UUID.
	 * @param mixed ...$args Remaining find() args (extend/files/register/schema).
	 *
	 * @return mixed The stored object row.
	 */
	public function find(int|string $id, ...$args): mixed;

	/**
	 * Save or update an object.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $object The object data.
	 * @param string|null $uuid The object UUID to overwrite.
	 *
	 * @return mixed The saved object row.
	 */
	public function saveObject(string $register, string $schema, array $object, ?string $uuid = null): mixed;
}//end interface

/**
 * Wire-contract tests for the ZtcPublishController publish endpoints.
 *
 * @covers \OCA\Dossiq\Controller\ZtcPublishController
 *
 * ZtcPublishController extends ZgwController, which composes NormalisesObjectRows, so
 * exercising it necessarily runs code declared on both. CI runs phpunit.xml
 * with beStrictAboutCoverageMetadata="true" and failOnRisky="true", which marks
 * executed-but-unlisted code risky and fails the run.
 *
 * @uses \OCA\Dossiq\Controller\ZgwController
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 */
class ZtcPublishControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The ZgwService mock — the controller's JWT gate and store accessor.
	 *
	 * @var ZgwService|MockObject
	 */
	private ZgwService $zgwService;

	/**
	 * The controller under test.
	 *
	 * @var ZtcPublishController
	 */
	private ZtcPublishController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->zgwService = $this->createMock(ZgwService::class);

		$this->controller = new ZtcPublishController(
			appName: 'dossiq',
			request: $this->request,
			zgwService: $this->zgwService,
		);
	}//end setUp()

	/**
	 * Configure the JWT gate to REFUSE.
	 *
	 * @return JSONResponse The refusal the gate answers with.
	 */
	private function refuseJwt(): JSONResponse {
		$refusal = new JSONResponse(
			data: ['code' => 'not_authenticated'],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
		$this->zgwService->method('validateJwtAuth')->willReturn($refusal);

		return $refusal;
	}//end refuseJwt()

	/**
	 * Configure the JWT gate to ACCEPT.
	 *
	 * @return void
	 */
	private function acceptJwt(): void {
		$this->zgwService->method('validateJwtAuth')->willReturn(null);
		$this->zgwService->method('consumerHasScope')->willReturnCallback(
			static fn (IRequest $request, string $component, string $scope): bool => $component === 'ztc' && $scope === 'catalogi.schrijven'
		);
	}//end acceptJwt()

	/**
	 * A stored draft zaaktype zt-1, its publish guard answering $refusal, and the saves recorded.
	 *
	 * @param array|null $refusal What the publish guard answers.
	 * @param array $saved The saves, filled by reference.
	 *
	 * @return ZgwBusinessRulesService|MockObject The guard, to assert on.
	 */
	private function storedDraftZaaktype(?array $refusal, array &$saved): ZgwBusinessRulesService {
		$this->acceptJwt();

		$objectService = $this->createMock(ZtcContractObjectServiceStub::class);
		$objectService->method('find')->willReturn(['id' => 'zt-1', 'isDraft' => true, 'title' => 'Melding']);
		$objectService->method('saveObject')->willReturnCallback(
			static function (string $register, string $schema, array $object, ?string $uuid = null) use (&$saved): array {
				$saved[] = $object;
				return $object;
			}
		);

		$guard = $this->createMock(ZgwBusinessRulesService::class);
		$guard->method('guardPublishEndpoint')->willReturn($refusal);

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('getBusinessRulesService')->willReturn($guard);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'ztc-register', 'sourceSchema' => 'zaaktype']
		);
		$this->zgwService->method('buildValidationError')->willReturnCallback(
			static fn (array $ruleResult): array => ['detail' => $ruleResult['detail'], 'code' => $ruleResult['code']]
		);
		$this->zgwService->method('createOutboundMapping')->willReturn(new \stdClass());
		$this->zgwService->method('applyOutboundMapping')->willReturn(['concept' => false]);

		return $guard;
	}//end storedDraftZaaktype()

	/**
	 * A caller without the catalogi write scope gets the ZGW permission error on all three routes, and nothing is read or saved.
	 *
	 * @return void
	 */
	public function testAllThreePublishRoutesRequireTheCatalogiWriteScope(): void {
		$this->zgwService->method('validateJwtAuth')->willReturn(null);
		$this->zgwService->method('consumerHasScope')->willReturn(false);
		$this->zgwService->expects($this->never())->method('getObjectService');

		$responses = [
			$this->controller->publishZaaktype(uuid: 'zt-1'),
			$this->controller->publishBesluittype(uuid: 'bt-1'),
			$this->controller->publishInformatieobjecttype(uuid: 'iot-1'),
		];

		foreach ($responses as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('permission_denied', $response->getData()['code']);
			$this->assertSame('Scope catalogi.schrijven is required for this operation.', $response->getData()['detail']);
		}
	}//end testAllThreePublishRoutesRequireTheCatalogiWriteScope()

	/**
	 * A zaaktype the publish guard refuses answers the guard's status and code, and is not saved.
	 *
	 * @return void
	 */
	public function testAZaaktypeThePublishGuardRefusesIsNotSaved(): void {
		$saved = [];
		$refusal = [
			'valid' => false,
			'status' => Http::STATUS_UNPROCESSABLE_ENTITY,
			'detail' => 'At least one status type must be marked as final',
			'code' => 'publish_validation_failed',
			'enrichedBody' => [],
		];
		$guard = $this->storedDraftZaaktype(refusal: $refusal, saved: $saved);
		$guard->expects($this->once())->method('guardPublishEndpoint')->with(
			'zaaktypen',
			['id' => 'zt-1', 'isDraft' => true, 'title' => 'Melding'],
			$this->anything(),
			['sourceRegister' => 'ztc-register', 'sourceSchema' => 'zaaktype']
		);

		$response = $this->controller->publishZaaktype(uuid: 'zt-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			['detail' => 'At least one status type must be marked as final', 'code' => 'publish_validation_failed'],
			$response->getData()
		);
		$this->assertSame([], $saved, 'a refused publish must not save the type');
	}//end testAZaaktypeThePublishGuardRefusesIsNotSaved()

	/**
	 * A zaaktype the publish guard lets through is saved as published and answers 201.
	 *
	 * @return void
	 */
	public function testAZaaktypeThePublishGuardPassesIsPublished(): void {
		$saved = [];
		$this->storedDraftZaaktype(refusal: null, saved: $saved);

		$response = $this->controller->publishZaaktype(uuid: 'zt-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $saved);
		$this->assertFalse($saved[0]['isDraft']);
	}//end testAZaaktypeThePublishGuardPassesIsPublished()

	/**
	 * All three publish routes consult the JWT gate first and return its
	 * refusal verbatim, publishing nothing.
	 *
	 * @return void
	 */
	public function testAllThreePublishRoutesRefuseAnUnauthenticatedCaller(): void {
		$refusal = $this->refuseJwt();
		$this->zgwService->expects($this->never())->method('getObjectService');

		$responses = [
			'publishZaaktype' => $this->controller->publishZaaktype(uuid: 'zt-1'),
			'publishBesluittype' => $this->controller->publishBesluittype(uuid: 'bt-1'),
			'publishInformatieobjecttype' => $this->controller->publishInformatieobjecttype(uuid: 'iot-1'),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame($refusal, $response, $endpoint . ' must return the JWT refusal verbatim');
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		}
	}//end testAllThreePublishRoutesRefuseAnUnauthenticatedCaller()

	/**
	 * Each route resolves the mapping for its OWN catalogi resource. This is
	 * the assertion that separates three otherwise identical delegators: a
	 * copy-paste would publish the wrong kind of catalogue object.
	 *
	 * @return void
	 */
	public function testEachPublishRouteAddressesItsOwnCatalogiResource(): void {
		$this->acceptJwt();
		$this->zgwService->method('getObjectService')->willReturn(
			$this->createMock(ZtcContractObjectServiceStub::class)
		);

		$seen = [];
		$this->zgwService->method('loadMappingConfig')->willReturnCallback(
			static function (string $zgwApi, string $resource) use (&$seen): ?array {
				$seen[] = $zgwApi . '/' . $resource;
				return null;
			}
		);
		$this->zgwService->method('mappingNotFoundResponse')->willReturn(
			new JSONResponse(data: ['detail' => 'no mapping'], statusCode: Http::STATUS_NOT_FOUND)
		);

		$statuses = [
			$this->controller->publishZaaktype(uuid: 'zt-1')->getStatus(),
			$this->controller->publishBesluittype(uuid: 'bt-1')->getStatus(),
			$this->controller->publishInformatieobjecttype(uuid: 'iot-1')->getStatus(),
		];

		$this->assertSame(
			['catalogi/zaaktypen', 'catalogi/besluittypen', 'catalogi/informatieobjecttypen'],
			$seen
		);
		$this->assertSame(
			[Http::STATUS_NOT_FOUND, Http::STATUS_NOT_FOUND, Http::STATUS_NOT_FOUND],
			$statuses,
			'an unmapped resource must answer 404, not publish and not 500'
		);
	}//end testEachPublishRouteAddressesItsOwnCatalogiResource()

	/**
	 * With OpenRegister unwired the route answers 503 and never looks up a
	 * mapping — distinct from the 404 above.
	 *
	 * @return void
	 */
	public function testPublishZaaktypeAnswers503WhenTheObjectStoreIsUnavailable(): void {
		$this->acceptJwt();
		$this->zgwService->method('getObjectService')->willReturn(null);
		$this->zgwService->expects($this->never())->method('loadMappingConfig');
		$this->zgwService->method('unavailableResponse')->willReturn(
			new JSONResponse(
				data: ['detail' => 'OpenRegister is not available'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			)
		);

		$response = $this->controller->publishZaaktype(uuid: 'zt-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['detail' => 'OpenRegister is not available'], $response->getData());
	}//end testPublishZaaktypeAnswers503WhenTheObjectStoreIsUnavailable()

	/**
	 * Publishing WRITES: the stored object is saved back with `isDraft` false,
	 * onto the same uuid and the resource's own register/schema, and the route
	 * answers 201 with the outbound-mapped body.
	 *
	 * @return void
	 */
	public function testPublishBesluittypeClearsTheDraftFlagAndAnswers201(): void {
		$this->acceptJwt();

		$saved = [];
		$objectService = $this->createMock(ZtcContractObjectServiceStub::class);
		$objectService->method('find')->willReturn(
			['id' => 'row-1', 'isDraft' => true, 'omschrijving' => 'Vergunning verleend']
		);
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				static function (
					string $register,
					string $schema,
					array $object,
					?string $uuid = null,
				) use (&$saved): array {
					$saved = [
						'register' => $register,
						'schema' => $schema,
						'object' => $object,
						'uuid' => $uuid,
					];
					return $object;
				}
			);

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'ztc-register', 'sourceSchema' => 'besluittype']
		);
		$this->zgwService->method('createOutboundMapping')->willReturn(new \stdClass());
		$this->zgwService->method('applyOutboundMapping')->willReturn(
			['url' => 'https://example.test/besluittypen/bt-1', 'concept' => false]
		);

		$response = $this->controller->publishBesluittype(uuid: 'bt-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['url' => 'https://example.test/besluittypen/bt-1', 'concept' => false], $response->getData());
		$this->assertFalse($saved['object']['isDraft'], 'publishing must clear the draft flag on the stored object');
		$this->assertSame('bt-1', $saved['uuid'], 'the publish must overwrite the object the route names');
		$this->assertSame('ztc-register', $saved['register']);
		$this->assertSame('besluittype', $saved['schema']);
	}//end testPublishBesluittypeClearsTheDraftFlagAndAnswers201()

	/**
	 * The publish loses the store's bookkeeping keys before writing back —
	 * saving `@self` / `id` / `organisation` into the object body is how a
	 * republish corrupts a catalogue row.
	 *
	 * @return void
	 */
	public function testPublishInformatieobjecttypeStripsTheStoreBookkeepingKeys(): void {
		$this->acceptJwt();

		$saved = [];
		$objectService = $this->createMock(ZtcContractObjectServiceStub::class);
		$objectService->method('find')->willReturn(
			[
				'@self' => ['id' => 'row-2', 'created' => '2026-01-01'],
				'id' => 'row-2',
				'organisation' => 'gemeente',
				'isDraft' => true,
				'omschrijving' => 'Bijlage',
			]
		);
		$objectService->method('saveObject')->willReturnCallback(
			static function (
				string $register,
				string $schema,
				array $object,
				?string $uuid = null,
			) use (&$saved): array {
				$saved = $object;
				return $object;
			}
		);

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'ztc-register', 'sourceSchema' => 'informatieobjecttype']
		);
		$this->zgwService->method('createOutboundMapping')->willReturn(new \stdClass());
		$this->zgwService->method('applyOutboundMapping')->willReturn(['concept' => false]);

		$response = $this->controller->publishInformatieobjecttype(uuid: 'iot-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertArrayNotHasKey('@self', $saved);
		$this->assertArrayNotHasKey('id', $saved);
		$this->assertArrayNotHasKey('organisation', $saved);
		$this->assertSame('Bijlage', $saved['omschrijving'], 'the payload itself must survive the publish');
	}//end testPublishInformatieobjecttypeStripsTheStoreBookkeepingKeys()

	/**
	 * A store failure during publish answers 400 with the reason rather than
	 * escaping as an unhandled 500.
	 *
	 * @return void
	 */
	public function testPublishZaaktypeAnswers400WhenTheStoreRefusesTheWrite(): void {
		$this->acceptJwt();

		$objectService = $this->createMock(ZtcContractObjectServiceStub::class);
		$objectService->method('find')->willThrowException(new \RuntimeException('Object not found'));

		$this->zgwService->method('getObjectService')->willReturn($objectService);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'ztc-register', 'sourceSchema' => 'zaaktype']
		);

		$response = $this->controller->publishZaaktype(uuid: 'zt-missing');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'Object not found'], $response->getData());
	}//end testPublishZaaktypeAnswers400WhenTheStoreRefusesTheWrite()
}//end class
