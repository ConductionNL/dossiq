<?php

/**
 * Characterisation tests for BrcController.
 *
 * Pins every route of the Besluiten API as the controller answers it: the token and scope gates,
 * the pass-through to the shared ZGW service, the 405 on a besluitinformatieobject, and the
 * cross-register work (brc-005, brc-006, brc-009). Written before the controller was split
 * (method-decomposition), so the split can be proven to change no answer and no write.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\BrcController;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\StoreFailureAnswer;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An object store that records what the Besluiten API reads, writes and deletes.
 */
final class BrcStore {
	/**
	 * Every call, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	public array $calls = [];

	/**
	 * Objects find() answers, by uuid; a missing uuid throws.
	 *
	 * @var array<string, mixed>
	 */
	public array $objects = [];

	/**
	 * What a search answers.
	 *
	 * @var array
	 */
	public array $searchResult = ['results' => []];

	/**
	 * Methods that throw when called.
	 *
	 * @var array<int, string>
	 */
	public array $failOn = [];

	/**
	 * Methods that OpenRegister refuses to the caller (NotAuthorizedException).
	 *
	 * @var array<int, string>
	 */
	public array $refuseOn = [];

	/**
	 * What saveObject() answers.
	 *
	 * @var mixed
	 */
	public mixed $saved = ['id' => 'new-uuid'];

	private function record(string $method, array $arguments): void {
		$this->calls[] = [$method, $arguments];
		if (in_array($method, $this->failOn, true) === true) {
			throw new \RuntimeException($method . ' is down');
		}

		if (in_array($method, $this->refuseOn, true) === true) {
			throw new \OCA\OpenRegister\Exception\NotAuthorizedException('may not ' . $method);
		}
	}//end record()

	public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
		$this->record('find', [$id, $register, $schema]);
		if (array_key_exists($id, $this->objects) === false) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
		}

		return $this->objects[$id];
	}//end find()

	public function saveObject(mixed $register, mixed $schema, array $object): mixed {
		$this->record('saveObject', [$register, $schema, $object]);
		return $this->saved;
	}//end saveObject()

	public function deleteObject(string $uuid, bool $_rbac = true, bool $_multitenancy = true): bool {
		$this->record('deleteObject', [$uuid, $_rbac, $_multitenancy]);
		return true;
	}//end deleteObject()

	public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array {
		return ['params' => $requestParams, 'register' => $register, 'schema' => $schema];
	}//end buildSearchQuery()

	public function searchObjectsPaginated(array $query): array {
		$this->record('search', [$query]);
		return $this->searchResult;
	}//end searchObjectsPaginated()

	/**
	 * The calls of one method.
	 *
	 * @param string $method The method.
	 *
	 * @return array<int, array> Their arguments.
	 */
	public function callsOf(string $method): array {
		return array_values(array_map(static fn (array $c): array => $c[1], array_filter($this->calls, static fn (array $c): bool => $c[0] === $method)));
	}//end callsOf()
}//end class

/**
 * Characterisation tests for the Besluiten API controller.
 *
 * @covers \OCA\Dossiq\Controller\BrcController
 * @covers \OCA\Dossiq\Controller\ZgwController
 * @covers \OCA\Dossiq\Service\Zgw\BrcCrossRegisterSync
 * @covers \OCA\Dossiq\Service\Zgw\BrcDocumentLinkHandler
 * @uses \OCA\Dossiq\Service\Zgw\StoreFailureAnswer
 * @uses \OCA\Dossiq\Service\Zgw\ZgwSearchScope
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class BrcControllerTest extends TestCase {
	private const BESLUIT = '11111111-1111-4111-8111-111111111111';

	private const ZAAK = '22222222-2222-4222-8222-222222222222';

	private const BIO = '33333333-3333-4333-8333-333333333333';

	private const BASE = 'https://nc.example.com/besluiten/v1/';

	private const DOCUMENT_URL = 'https://nc.example.com/documenten/v1/enkelvoudiginformatieobjecten/44444444-4444-4444-8444-444444444444';

	private const MAPPINGS = [
		'besluiten/besluiten' => ['sourceRegister' => '7', 'sourceSchema' => '70'],
		'besluiten/besluitinformatieobjecten' => ['sourceRegister' => '7', 'sourceSchema' => '71'],
		'documenten/objectinformatieobjecten' => ['sourceRegister' => '7', 'sourceSchema' => '72'],
		'zaken/zaken' => ['sourceRegister' => '8', 'sourceSchema' => '80'],
	];

	/**
	 * The shared ZGW service.
	 *
	 * @var ZgwService&MockObject
	 */
	private ZgwService $zgw;

	/**
	 * The business rules the ZGW service hands out.
	 *
	 * @var ZgwBusinessRulesService&MockObject
	 */
	private ZgwBusinessRulesService $rules;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The object store, or null for an instance without OpenRegister.
	 *
	 * @var BrcStore|null
	 */
	private ?BrcStore $store;

	/**
	 * The mapping configuration per "api/resource"; remove one to make it missing.
	 *
	 * @var array<string, array>
	 */
	private array $mappings = self::MAPPINGS;

	/**
	 * The token error, or null for a valid token.
	 *
	 * @var JSONResponse|null
	 */
	private ?JSONResponse $authError = null;

	/**
	 * The scopes the consumer holds.
	 *
	 * @var array<int, string>
	 */
	private array $scopes = ['besluiten.aanmaken', 'besluiten.bijwerken', 'besluiten.verwijderen'];

	/**
	 * Every scope asked for, as "component:scope".
	 *
	 * @var array<int, string>
	 */
	private array $scopesAsked = [];

	/**
	 * What the business rules answer.
	 *
	 * @var array|null Null answers valid with the body it was given.
	 */
	private ?array $ruleResult = null;

	/**
	 * Every validate() call's arguments, in order: api, resource, action, body, stored object,
	 * object service, mapping configuration.
	 *
	 * @var array<int, array>
	 */
	private array $validated = [];

	/**
	 * Every notification published.
	 *
	 * @var array<int, array>
	 */
	private array $notifications = [];

	/**
	 * Every log line, as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * Every pass-through to a shared handler, as [handler, arguments after the request].
	 *
	 * @var array<int, array>
	 */
	private array $handled = [];

	/**
	 * What handleCreate answers.
	 *
	 * @var JSONResponse
	 */
	private JSONResponse $created;

	/**
	 * The schema the zaakbesluit is stored in.
	 *
	 * @var string
	 */
	private string $caseDecisionSchema = '81';

	/**
	 * The request body.
	 *
	 * @var array
	 */
	private array $body = [];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store   = new BrcStore();
		$this->created = new JSONResponse(data: [], statusCode: 201);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['besluit' => 'x', '_route' => 'r']);
		$this->rules = $this->createMock(ZgwBusinessRulesService::class);
		$this->rules->method('validate')->willReturnCallback(
			function (...$arguments): array {
				$this->validated[] = array_slice($arguments, 0, 7);
				return ($this->ruleResult ?? ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $arguments[3]]);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->log[] = $level . ': ' . $message;
				}
			);
		}

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturnCallback(fn (): ?JSONResponse => $this->authError);
		$zgw->method('consumerHasScope')->willReturnCallback(
			function (IRequest $request, string $component, string $scope): bool {
				$this->scopesAsked[] = $component . ':' . $scope;
				return in_array($scope, $this->scopes, true);
			}
		);
		$zgw->method('getObjectService')->willReturnCallback(fn (): ?object => $this->store);
		$zgw->method('getBusinessRulesService')->willReturn($this->rules);
		$zgw->method('getLogger')->willReturn($logger);
		$zgw->method('loadMappingConfig')->willReturnCallback(
			fn (string $zgwApi, string $resource): ?array => ($this->mappings[$zgwApi . '/' . $resource] ?? null)
		);
		$zgw->method('unavailableResponse')->willReturn(new JSONResponse(data: ['detail' => 'unavailable'], statusCode: 503));
		$zgw->method('mappingNotFoundResponse')->willReturnCallback(
			static fn (string $zgwApi, string $resource): JSONResponse => new JSONResponse(data: ['detail' => 'no mapping ' . $zgwApi . '/' . $resource], statusCode: 404)
		);
		$zgw->method('translateQueryParams')->willReturnCallback(
			static fn (array $params, array $mappingConfig): array => ['decision' => $params['besluit']]
		);
		$zgw->method('buildBaseUrl')->willReturnCallback(
			static fn (IRequest $request, string $zgwApi, string $resource): string => 'https://nc.example.com/' . $zgwApi . '/v1/' . $resource
		);
		$zgw->method('createOutboundMapping')->willReturn(new \stdClass());
		$zgw->method('createInboundMapping')->willReturn(new \stdClass());
		$zgw->method('applyOutboundMapping')->willReturnCallback(
			static fn (array $objectData, object $mapping, array $mappingConfig, string $baseUrl): array => ['out' => $objectData, 'base' => $baseUrl]
		);
		$zgw->method('applyInboundMapping')->willReturnCallback(
			static fn (array $body, object $mapping, array $mappingConfig): array => ['in' => $body]
		);
		$zgw->method('getRequestBody')->willReturnCallback(fn (): array => $this->body);
		$zgw->method('buildValidationError')->willReturnCallback(static fn (array $ruleResult): array => ['invalid' => $ruleResult['detail']]);
		$zgw->method('publishNotification')->willReturnCallback(
			function (string $zgwApi, string $resource, string $resourceUrl, string $action): void {
				$this->notifications[] = [$zgwApi, $resource, $resourceUrl, $action];
			}
		);
		$answers = new StoreFailureAnswer(logger: $logger);
		$zgw->method('storeFailureResponse')->willReturnCallback(
			static fn (\Throwable $error, string $operation, string $object): JSONResponse => $answers->respond(error: $error, operation: $operation, object: $object)
		);
		$zgw->method('notFoundResponse')->willReturnCallback(static fn (): JSONResponse => $answers->notFound());
		$zgw->method('notifyDeleted')->willReturnCallback(
			function (IRequest $request, string $zgwApi, string $resource, string $uuid): void {
				$this->notifications[] = [$zgwApi, $resource, self::BASE . $resource . '/' . $uuid, 'destroy'];
			}
		);
		// The arguments the controller passes; the handlers' own defaults behind them are cut off.
		$passed = ['handleIndex' => 2, 'handleShow' => 3, 'handleUpdate' => 4, 'handleDestroy' => 3, 'handleAudittrailIndex' => 3, 'handleAudittrailShow' => 4];
		foreach ($passed as $handler => $count) {
			$zgw->method($handler)->willReturnCallback(
				function (IRequest $request, ...$arguments) use ($handler, $count): JSONResponse {
					$this->handled[] = [$handler, array_slice($arguments, 0, $count)];
					return new JSONResponse(data: ['by' => $handler]);
				}
			);
		}

		$zgw->method('handleCreate')->willReturnCallback(
			function (IRequest $request, ...$arguments): JSONResponse {
				$this->handled[] = ['handleCreate', array_slice($arguments, 0, 2)];
				return $this->created;
			}
		);
		$this->zgw = $zgw;
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return BrcController
	 */
	private function controller(): BrcController {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => (['case_decision_schema' => $this->caseDecisionSchema][$key] ?? '')
		);

		return new BrcController(appName: 'dossiq', request: $this->request, zgwService: $this->zgw, settingsService: $settings);
	}//end controller()

	/**
	 * Every route, called with a besluit.
	 *
	 * @return array<string, \Closure>
	 */
	private function routes(): array {
		return [
			'index' => static fn (BrcController $c): JSONResponse => $c->index('besluiten'),
			'create' => static fn (BrcController $c): JSONResponse => $c->create('besluiten'),
			'show' => static fn (BrcController $c): JSONResponse => $c->show('besluiten', self::BESLUIT),
			'update' => static fn (BrcController $c): JSONResponse => $c->update('besluiten', self::BESLUIT),
			'patch' => static fn (BrcController $c): JSONResponse => $c->patch('besluiten', self::BESLUIT),
			'destroy' => static fn (BrcController $c): JSONResponse => $c->destroy('besluiten', self::BESLUIT),
			'audittrailIndex' => static fn (BrcController $c): JSONResponse => $c->audittrailIndex('besluiten', self::BESLUIT),
			'audittrailShow' => static fn (BrcController $c): JSONResponse => $c->audittrailShow('besluiten', self::BESLUIT, 'a-1'),
		];
	}//end routes()

	/**
	 * A bad token is answered by every route before anything is read or written.
	 *
	 * @return void
	 */
	public function testEveryRouteStopsAtABadToken(): void {
		$this->authError = new JSONResponse(data: ['detail' => 'no token'], statusCode: 403);

		foreach ($this->routes() as $name => $call) {
			$this->assertSame($this->authError, $call($this->controller()), $name);
		}

		$this->assertSame([], $this->handled);
		$this->assertSame([], $this->scopesAsked);
		$this->assertSame([], $this->store->calls);
	}//end testEveryRouteStopsAtABadToken()

	/**
	 * Each write asks for its own scope and refuses without it; reads ask for none.
	 *
	 * @return void
	 */
	public function testEachWriteNeedsItsScope(): void {
		$this->scopes = [];
		$routes = $this->routes();

		foreach (['create' => 'besluiten.aanmaken', 'update' => 'besluiten.bijwerken', 'patch' => 'besluiten.bijwerken', 'destroy' => 'besluiten.verwijderen'] as $name => $scope) {
			$response = $routes[$name]($this->controller());

			$this->assertSame(403, $response->getStatus(), $name);
			$this->assertSame(
				[
					'type' => 'PermissionDenied',
					'code' => 'permission_denied',
					'title' => 'Insufficient scope.',
					'status' => 403,
					'detail' => 'Scope ' . $scope . ' is required for this operation.',
				],
				$response->getData(),
				$name
			);
		}

		$this->assertSame(['brc:besluiten.aanmaken', 'brc:besluiten.bijwerken', 'brc:besluiten.bijwerken', 'brc:besluiten.verwijderen'], $this->scopesAsked);
		$this->assertSame([], $this->handled);
		$this->assertSame([], $this->store->calls);

		foreach (['index', 'show', 'audittrailShow'] as $name) {
			$this->assertSame(200, $routes[$name]($this->controller())->getStatus(), $name);
		}

		$this->assertCount(4, $this->scopesAsked);
	}//end testEachWriteNeedsItsScope()

	/**
	 * What is not special goes to the shared handler with the api, the resource and the uuid.
	 *
	 * @return void
	 */
	public function testThePlainRoutesPassThrough(): void {
		$c = $this->controller();

		$this->assertSame(['by' => 'handleIndex'], $c->index('besluiten')->getData());
		$this->assertSame(['by' => 'handleShow'], $c->show('besluitinformatieobjecten', self::BIO)->getData());
		$this->assertSame(['by' => 'handleUpdate'], $c->update('besluiten', self::BESLUIT)->getData());
		$this->assertSame(['by' => 'handleUpdate'], $c->patch('besluiten', self::BESLUIT)->getData());
		$this->assertSame(['by' => 'handleDestroy'], $c->destroy('anders', 'u-1')->getData());
		$this->assertSame(['by' => 'handleAudittrailShow'], $c->audittrailShow('besluiten', self::BESLUIT, 'a-1')->getData());
		$this->assertSame($this->created, $c->create('anders'));
		$this->assertSame(
			[
				['handleIndex', ['besluiten', 'besluiten']],
				['handleShow', ['besluiten', 'besluitinformatieobjecten', self::BIO]],
				['handleUpdate', ['besluiten', 'besluiten', self::BESLUIT, false]],
				['handleUpdate', ['besluiten', 'besluiten', self::BESLUIT, true]],
				['handleDestroy', ['besluiten', 'anders', 'u-1']],
				['handleAudittrailShow', ['besluiten', 'besluiten', self::BESLUIT, 'a-1']],
				['handleCreate', ['besluiten', 'anders']],
			],
			$this->handled
		);
		$this->assertSame([], $this->store->calls);
	}//end testThePlainRoutesPassThrough()

	/**
	 * Brc-004: a besluitinformatieobject cannot be changed, by PUT or by PATCH.
	 *
	 * @return void
	 */
	public function testADocumentLinkCannotBeChanged(): void {
		foreach (['update', 'patch'] as $method) {
			$response = $this->controller()->$method('besluitinformatieobjecten', self::BIO);

			$this->assertSame(405, $response->getStatus(), $method);
			$this->assertSame(['detail' => 'Method not allowed'], $response->getData(), $method);
		}

		$this->assertSame([], $this->handled);
		$this->scopes = [];
		$this->assertSame(403, $this->controller()->update('besluitinformatieobjecten', self::BIO)->getStatus());
	}//end testADocumentLinkCannotBeChanged()

	/**
	 * Brc-009d: the audit trail of a resource that is not there is a 404; without a store or a
	 * mapping the existence is not checked.
	 *
	 * @return void
	 */
	public function testTheAuditTrailNeedsItsResource(): void {
		$missing = $this->controller()->audittrailIndex('besluiten', self::BESLUIT);
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['detail' => 'Not found'], $missing->getData());
		$this->assertSame([], $this->handled);

		$this->store->objects[self::BESLUIT] = ['id' => self::BESLUIT];
		$this->assertSame(['by' => 'handleAudittrailIndex'], $this->controller()->audittrailIndex('besluiten', self::BESLUIT)->getData());
		$this->assertSame([self::BESLUIT, '7', '70'], $this->store->callsOf('find')[1]);
		$this->assertSame([['handleAudittrailIndex', ['besluiten', 'besluiten', self::BESLUIT]]], $this->handled);

		$this->assertSame(['by' => 'handleAudittrailIndex'], $this->controller()->audittrailIndex('onbekend', 'u-9')->getData());
		$this->store = null;
		$this->assertSame(['by' => 'handleAudittrailIndex'], $this->controller()->audittrailIndex('besluiten', 'u-9')->getData());
	}//end testTheAuditTrailNeedsItsResource()

	/**
	 * Brc-006: a created besluit that names a zaak gets its zaakbesluit in the Zaken register.
	 *
	 * @return void
	 */
	public function testACreatedBesluitIsLinkedToItsZaak(): void {
		$url = self::BASE . 'besluiten/' . self::BESLUIT;
		$this->created = new JSONResponse(data: ['case' => 'https://nc.example.com/zaken/v1/zaken/' . self::ZAAK, 'url' => $url], statusCode: 201);

		$this->assertSame($this->created, $this->controller()->create('besluiten'));
		$this->assertSame([['8', '81', ['case' => self::ZAAK, 'decision' => $url]]], $this->store->callsOf('saveObject'));
		$this->assertSame(['info: brc-006: Created zaakbesluit for zaak=' . self::ZAAK . ' besluit=' . $url], $this->log);
		$this->assertSame([['handleCreate', ['besluiten', 'besluiten']]], $this->handled);
	}//end testACreatedBesluitIsLinkedToItsZaak()

	/**
	 * Brc-006 links nothing when the create failed, the besluit names no zaak or carries no URL,
	 * the zaak URL holds no uuid, or the link cannot be stored; the create answer stands.
	 *
	 * @return void
	 */
	public function testTheZaakLinkIsSkippedWhenItCannotBeMade(): void {
		$url  = self::BASE . 'besluiten/' . self::BESLUIT;
		$zaak = 'https://nc.example.com/zaken/v1/zaken/' . self::ZAAK;

		foreach ([
			new JSONResponse(data: ['case' => $zaak, 'url' => $url], statusCode: 400),
			new JSONResponse(data: ['case' => '', 'url' => $url], statusCode: 201),
			new JSONResponse(data: ['url' => $url], statusCode: 201),
			new JSONResponse(data: ['case' => $zaak], statusCode: 201),
			new JSONResponse(data: ['case' => 'https://nc.example.com/zaken/v1/zaken/geen-uuid', 'url' => $url], statusCode: 201),
		] as $created) {
			$this->created = $created;
			$this->assertSame($created, $this->controller()->create('besluiten'));
		}

		$this->assertSame([], $this->store->calls);
		$this->assertSame([], $this->log);

		$this->created = new JSONResponse(data: ['case' => $zaak, 'url' => $url], statusCode: 201);

		$this->caseDecisionSchema = '';
		$this->controller()->create('besluiten');
		$this->caseDecisionSchema = '81';
		unset($this->mappings['zaken/zaken']);
		$this->controller()->create('besluiten');
		$this->mappings = self::MAPPINGS;
		$this->store->failOn = ['saveObject'];
		$this->assertSame($this->created, $this->controller()->create('besluiten'));
		$this->store = null;
		$this->assertSame($this->created, $this->controller()->create('besluiten'));

		$this->assertSame(
			[
				'debug: brc-006: case_decision_schema not configured, skipping zaakbesluit sync',
				'error: brc-006: the besluit was stored, but its zaakbesluit was not: saveObject is down',
			],
			$this->log
		);
	}//end testTheZaakLinkIsSkippedWhenItCannotBeMade()

	/**
	 * Besluitinformatieobjecten are listed as a plain array, at most a hundred, filtered by the query.
	 *
	 * @return void
	 */
	public function testDocumentLinksAreListedAsAPlainArray(): void {
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'bio-2'];
			}//end jsonSerialize()
		};
		$this->store->searchResult = ['results' => [['id' => 'bio-1'], $entity]];

		$response = $this->controller()->index('besluitinformatieobjecten');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				['out' => ['id' => 'bio-1'], 'base' => self::BASE . 'besluitinformatieobjecten'],
				['out' => ['id' => 'bio-2'], 'base' => self::BASE . 'besluitinformatieobjecten'],
			],
			$response->getData()
		);
		$this->assertSame([[['params' => ['decision' => 'x', '_limit' => 100], 'register' => '7', 'schema' => '71']]], $this->store->callsOf('search'));
		$this->assertSame([], $this->handled);
	}//end testDocumentLinksAreListedAsAPlainArray()

	/**
	 * The three besluitinformatieobject routes and the besluit delete answer 503 without a
	 * store and 404 without their mapping.
	 *
	 * @return void
	 */
	public function testTheOwnRoutesNeedAStoreAndAMapping(): void {
		$calls = [
			'besluitinformatieobjecten' => [
				static fn (BrcController $c): JSONResponse => $c->index('besluitinformatieobjecten'),
				static fn (BrcController $c): JSONResponse => $c->create('besluitinformatieobjecten'),
				static fn (BrcController $c): JSONResponse => $c->destroy('besluitinformatieobjecten', self::BIO),
			],
			'besluiten' => [static fn (BrcController $c): JSONResponse => $c->destroy('besluiten', self::BESLUIT)],
		];

		foreach ($calls as $resource => $routes) {
			foreach ($routes as $index => $call) {
				$this->mappings = self::MAPPINGS;
				unset($this->mappings['besluiten/' . $resource]);
				$this->store = new BrcStore();
				$noMapping   = $call($this->controller());

				$this->assertSame(404, $noMapping->getStatus(), $resource . $index);
				$this->assertSame(['detail' => 'no mapping besluiten/' . $resource], $noMapping->getData(), $resource . $index);
				$this->assertSame([], $this->store->calls, $resource . $index);

				$this->store = null;
				$this->assertSame(503, $call($this->controller())->getStatus(), $resource . $index);
			}
		}

		$this->assertSame([], $this->notifications);
	}//end testTheOwnRoutesNeedAStoreAndAMapping()

	/**
	 * A failed list is a 500 that does not leak the cause.
	 *
	 * @return void
	 */
	public function testAFailedListIsAnInternalError(): void {
		$this->store->failOn = ['search'];

		$response = $this->controller()->index('besluitinformatieobjecten');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['detail' => 'Internal server error'], $response->getData());
		$this->assertSame(['error: BRC list BIO error: search is down'], $this->log);
	}//end testAFailedListIsAnInternalError()

	/**
	 * Brc-005a: a created besluitinformatieobject is stored through the mapping, mirrored as an
	 * objectinformatieobject in the Documenten register, and announced.
	 *
	 * @return void
	 */
	public function testACreatedDocumentLinkIsMirroredInTheDocumentsRegister(): void {
		$decisionUrl = self::BASE . 'besluiten/' . self::BESLUIT;
		$this->body  = ['decision' => $decisionUrl, 'informatieobject' => self::DOCUMENT_URL];
		$this->store->saved = ['@self' => ['id' => self::BIO]];

		$response = $this->controller()->create('besluitinformatieobjecten');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['out' => ['@self' => ['id' => self::BIO]], 'base' => self::BASE . 'besluitinformatieobjecten'], $response->getData());
		$this->assertSame(
			[
				['7', '71', ['in' => $this->body]],
				['7', '72', ['document' => self::DOCUMENT_URL, 'object' => $decisionUrl, 'objectType' => 'decision']],
			],
			$this->store->callsOf('saveObject')
		);
		$this->assertSame([['besluiten', 'besluitinformatieobjecten', self::BASE . 'besluitinformatieobjecten/' . self::BIO, 'create']], $this->notifications);
		$this->assertSame(
			['besluiten', 'besluitinformatieobjecten', 'create', $this->body, null, $this->store, ['sourceRegister' => '7', 'sourceSchema' => '71']],
			$this->validated[0]
		);
		$this->assertSame([], $this->handled);
	}//end testACreatedDocumentLinkIsMirroredInTheDocumentsRegister()

	/**
	 * The rules may enrich the body, and their enriched body is what is stored and mirrored.
	 *
	 * @return void
	 */
	public function testTheEnrichedBodyIsWhatIsStored(): void {
		$this->body       = ['decision' => 'sent'];
		$this->ruleResult = ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => ['decision' => 'd-url', 'informatieobject' => 'io-url', 'extra' => 1]];

		$this->controller()->create('besluitinformatieobjecten');

		$this->assertSame(
			[
				['7', '71', ['in' => ['decision' => 'd-url', 'informatieobject' => 'io-url', 'extra' => 1]]],
				['7', '72', ['document' => 'io-url', 'object' => 'd-url', 'objectType' => 'decision']],
			],
			$this->store->callsOf('saveObject')
		);
		$this->assertSame([['besluiten', 'besluitinformatieobjecten', self::BASE . 'besluitinformatieobjecten/new-uuid', 'create']], $this->notifications);
	}//end testTheEnrichedBodyIsWhatIsStored()

	/**
	 * A refused besluitinformatieobject is answered with the rule's status and stores nothing.
	 *
	 * @return void
	 */
	public function testARefusedDocumentLinkStoresNothing(): void {
		$this->ruleResult = ['valid' => false, 'status' => 422, 'detail' => 'nee'];

		$response = $this->controller()->create('besluitinformatieobjecten');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['invalid' => 'nee'], $response->getData());
		$this->assertSame([], $this->store->calls);
		$this->assertSame([], $this->notifications);
	}//end testARefusedDocumentLinkStoresNothing()

	/**
	 * The mirror is skipped without both URLs or without the Documenten mapping, and a mirror that
	 * fails is logged while the create still answers 201.
	 *
	 * @return void
	 */
	public function testTheMirrorIsBestEffort(): void {
		$this->body = ['decision' => 'd-url'];
		$this->assertSame(201, $this->controller()->create('besluitinformatieobjecten')->getStatus());
		$this->body = ['informatieobject' => 'io-url'];
		$this->assertSame(201, $this->controller()->create('besluitinformatieobjecten')->getStatus());
		$this->body = ['decision' => 'd-url', 'informatieobject' => 'io-url'];
		unset($this->mappings['documenten/objectinformatieobjecten']);
		$this->assertSame(201, $this->controller()->create('besluitinformatieobjecten')->getStatus());

		$this->assertCount(3, $this->store->callsOf('saveObject'));
		$this->assertSame([], $this->log);
		$this->assertCount(3, $this->notifications);
	}//end testTheMirrorIsBestEffort()

	/**
	 * A create that fails is a 400 carrying the cause, and announces nothing.
	 *
	 * @return void
	 */
	public function testAFailedDocumentLinkCreateIsABadRequest(): void {
		$this->store->failOn = ['saveObject'];

		$response = $this->controller()->create('besluitinformatieobjecten');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['detail' => 'saveObject is down'], $response->getData());
		$this->assertSame(['error: BRC create BIO error: saveObject is down'], $this->log);
		$this->assertSame([], $this->notifications);
	}//end testAFailedDocumentLinkCreateIsABadRequest()

	/**
	 * Brc-005b: deleting a besluitinformatieobject deletes it, then its mirror in the Documenten
	 * register outside the caller's own access, and announces it.
	 *
	 * @return void
	 */
	public function testADeletedDocumentLinkTakesItsMirrorAlong(): void {
		$this->store->objects[self::BIO] = ['decision' => self::BESLUIT, 'document' => self::DOCUMENT_URL];
		$this->store->searchResult = ['results' => [['id' => 'oio-1'], ['@self' => ['id' => 'oio-2']], ['title' => 'no id']]];

		$response = $this->controller()->destroy('besluitinformatieobjecten', self::BIO);

		$this->assertSame(204, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame([[self::BIO, '7', '71']], $this->store->callsOf('find'));
		$this->assertSame(
			[[['params' => ['object' => self::BASE . 'besluiten/' . self::BESLUIT, 'document' => self::DOCUMENT_URL], 'register' => 7, 'schema' => 72]]],
			$this->store->callsOf('search')
		);
		$this->assertSame([[self::BIO, true, true], ['oio-1', false, false], ['oio-2', false, false]], $this->store->callsOf('deleteObject'));
		$this->assertSame([['besluiten', 'besluitinformatieobjecten', self::BASE . 'besluitinformatieobjecten/' . self::BIO, 'destroy']], $this->notifications);
		$this->assertSame([], $this->log);
	}//end testADeletedDocumentLinkTakesItsMirrorAlong()

	/**
	 * The mirror is left alone when the link names no besluit or no document, is named as an
	 * orphan when its mapping cannot be searched, and a failed mirror delete is only logged.
	 *
	 * @return void
	 */
	public function testTheMirrorDeleteIsBestEffort(): void {
		$this->store->objects[self::BIO] = ['decision' => self::BESLUIT];
		$this->assertSame(204, $this->controller()->destroy('besluitinformatieobjecten', self::BIO)->getStatus());
		$this->store->objects[self::BIO] = ['document' => self::DOCUMENT_URL];
		$this->assertSame(204, $this->controller()->destroy('besluitinformatieobjecten', self::BIO)->getStatus());
		$this->assertSame([], $this->store->callsOf('search'));

		$this->store->objects[self::BIO] = ['decision' => self::BESLUIT, 'document' => self::DOCUMENT_URL];
		unset($this->mappings['documenten/objectinformatieobjecten']);
		$this->assertSame(204, $this->controller()->destroy('besluitinformatieobjecten', self::BIO)->getStatus());
		$this->mappings['documenten/objectinformatieobjecten'] = ['sourceRegister' => 'documents', 'sourceSchema' => '72'];
		$this->assertSame(204, $this->controller()->destroy('besluitinformatieobjecten', self::BIO)->getStatus());
		$this->mappings = self::MAPPINGS;
		$this->store->failOn = ['search'];
		$this->assertSame(204, $this->controller()->destroy('besluitinformatieobjecten', self::BIO)->getStatus());

		$this->assertSame(
			[
				'error: brc-005b: objectinformatieobject mapping has no searchable register/schema, so the OIO for ' . self::DOCUMENT_URL . ' is being left behind as an orphan',
				'error: brc-005b: the besluitinformatieobject was deleted, but its objectinformatieobject in the DRC was not: search is down',
			],
			$this->log
		);
		$this->assertCount(5, $this->notifications);
	}//end testTheMirrorDeleteIsBestEffort()

	/**
	 * A besluitinformatieobject or besluit that is not there, or whose delete fails, is a 404.
	 *
	 * @return void
	 */
	public function testAMissingResourceCannotBeDeleted(): void {
		$bio = $this->controller()->destroy('besluitinformatieobjecten', self::BIO);
		$besluit = $this->controller()->destroy('besluiten', self::BESLUIT);

		$this->assertSame(404, $bio->getStatus());
		$this->assertSame(['detail' => 'Not found'], $bio->getData());
		$this->assertSame(404, $besluit->getStatus());
		$this->assertSame(['detail' => 'Not found'], $besluit->getData());
		$this->assertSame(['info: {operation}: {object} not found', 'info: {operation}: {object} not found'], $this->log);
		$this->assertSame([], $this->store->callsOf('deleteObject'));
		$this->assertSame([], $this->notifications);

		// A besluit that is there but could not be deleted is not "not found" (Q-dossiq-L11-2).
		$this->store->objects[self::BESLUIT] = ['id' => self::BESLUIT];
		$this->store->failOn = ['deleteObject'];
		$down = $this->controller()->destroy('besluiten', self::BESLUIT);
		$this->assertSame(503, $down->getStatus());
		$this->assertSame('store-not-reached', $down->getData()['code']);

		$this->store->failOn   = [];
		$this->store->refuseOn = ['deleteObject'];
		$this->assertSame(403, $this->controller()->destroy('besluiten', self::BESLUIT)->getStatus());
		$this->assertSame([], $this->notifications);
	}//end testAMissingResourceCannotBeDeleted()

	/**
	 * Brc-009: deleting a besluit runs the destroy rules on the stored besluit, deletes the
	 * objectinformatieobjecten that point at it, then the besluit, and announces it.
	 *
	 * @return void
	 */
	public function testADeletedBesluitTakesItsDocumentMirrorsAlong(): void {
		$this->store->objects[self::BESLUIT] = ['id' => self::BESLUIT, 'title' => 'B-1'];
		$this->store->searchResult = ['results' => [['id' => 'oio-1'], ['id' => '']]];

		$response = $this->controller()->destroy('besluiten', self::BESLUIT);

		$this->assertSame(204, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame(
			['besluiten', 'besluiten', 'destroy', [], ['id' => self::BESLUIT, 'title' => 'B-1'], null, null],
			$this->validated[0]
		);
		$this->assertSame(
			[[['params' => ['object' => self::BASE . 'besluiten/' . self::BESLUIT], 'register' => 7, 'schema' => 72]]],
			$this->store->callsOf('search')
		);
		$this->assertSame([['oio-1', false, false], [self::BESLUIT, true, true]], $this->store->callsOf('deleteObject'));
		$this->assertSame([['besluiten', 'besluiten', self::BASE . 'besluiten/' . self::BESLUIT, 'destroy']], $this->notifications);
	}//end testADeletedBesluitTakesItsDocumentMirrorsAlong()

	/**
	 * A besluit the rules refuse to delete keeps itself and its mirrors.
	 *
	 * @return void
	 */
	public function testARefusedBesluitDeleteDeletesNothing(): void {
		$this->store->objects[self::BESLUIT] = ['id' => self::BESLUIT];
		$this->ruleResult = ['valid' => false, 'status' => 400, 'detail' => 'nee'];

		$response = $this->controller()->destroy('besluiten', self::BESLUIT);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['invalid' => 'nee'], $response->getData());
		$this->assertSame([], $this->store->callsOf('deleteObject'));
		$this->assertSame([], $this->store->callsOf('search'));
	}//end testARefusedBesluitDeleteDeletesNothing()

	/**
	 * The mirrors of a besluit are named as orphans when their mapping cannot be searched, and a
	 * failed mirror delete is logged; the besluit goes either way.
	 *
	 * @return void
	 */
	public function testTheBesluitGoesEvenWhenItsMirrorsCannot(): void {
		$this->store->objects[self::BESLUIT] = ['id' => self::BESLUIT];
		$url = self::BASE . 'besluiten/' . self::BESLUIT;

		unset($this->mappings['documenten/objectinformatieobjecten']);
		$this->assertSame(204, $this->controller()->destroy('besluiten', self::BESLUIT)->getStatus());
		$this->mappings['documenten/objectinformatieobjecten'] = ['sourceRegister' => '7'];
		$this->assertSame(204, $this->controller()->destroy('besluiten', self::BESLUIT)->getStatus());
		$this->mappings = self::MAPPINGS;
		$this->store->failOn = ['search'];
		$this->assertSame(204, $this->controller()->destroy('besluiten', self::BESLUIT)->getStatus());

		$this->assertSame(
			[
				'error: brc-005b: objectinformatieobject mapping has no searchable register/schema, so the OIOs of ' . $url . ' are being left behind as orphans',
				'error: brc-009: the objectinformatieobjecten of a besluit were not all deleted from the DRC: search is down',
			],
			$this->log
		);
		$this->assertSame([[self::BESLUIT, true, true], [self::BESLUIT, true, true], [self::BESLUIT, true, true]], $this->store->callsOf('deleteObject'));
	}//end testTheBesluitGoesEvenWhenItsMirrorsCannot()
}//end class
