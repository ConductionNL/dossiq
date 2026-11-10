<?php

/**
 * AcController characterisation.
 *
 * Pins the ZGW Autorisaties endpoints as they answer today: who is let
 * through and who is refused, the exact invalidParams each AC business rule
 * (ac-001, ac-002, ac-003) produces and in which order, and the applicatie
 * shape that is returned. The controller is split into named steps behind
 * these tests, so every allowed and refused outcome must stay identical.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\AcController;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Allowed and refused outcomes of every applicatie endpoint.
 *
 * @covers \OCA\Dossiq\Controller\AcController
 * @covers \OCA\Dossiq\Controller\Support\ApplicatieRules
 * @covers \OCA\Dossiq\Controller\Support\ApplicatieRepresentation
 */
class AcControllerCharacterisationTest extends TestCase {

	/**
	 * The shared ZGW service.
	 *
	 * @var ZgwService&MockObject
	 */
	private ZgwService $zgwService;

	/**
	 * The incoming request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The logger behind the ZGW service.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The in-memory consumer mapper.
	 *
	 * @var AcFakeConsumerMapper
	 */
	private AcFakeConsumerMapper $mapper;

	/**
	 * Scopes the calling consumer holds.
	 *
	 * @var array<int, string>
	 */
	private array $heldScopes = [];

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Request body.
	 *
	 * @var array<string, mixed>
	 */
	private array $body = [];

	/**
	 * The authentication refusal, when the JWT does not verify.
	 *
	 * @var JSONResponse|null
	 */
	private ?JSONResponse $authError = null;

	/**
	 * Whether the consumer mapper is available.
	 *
	 * @var bool
	 */
	private bool $mapperAvailable = true;

	/**
	 * Build the controller's collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new AcFakeConsumerMapper();
		$this->logger = $this->createMock(LoggerInterface::class);

		$payload = rtrim(strtr(base64_encode((string)json_encode(['client_id' => 'caller-app'])), '+/', '-_'), '=');
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturn('Bearer aaa.' . $payload . '.ccc');
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);

		$this->zgwService = $this->createMock(ZgwService::class);
		$this->zgwService->method('validateJwtAuth')->willReturnCallback(fn () => $this->authError);
		$this->zgwService->method('consumerHasScope')->willReturnCallback(
			fn ($request, string $component, string $scope) => in_array($scope, $this->heldScopes, true)
		);
		$this->zgwService->method('getConsumerMapper')->willReturnCallback(
			fn () => ($this->mapperAvailable === true ? $this->mapper : null)
		);
		$this->zgwService->method('getRequestBody')->willReturnCallback(fn () => $this->body);
		$this->zgwService->method('buildBaseUrl')->willReturn('https://host/autorisaties/v1/applicaties');
		$this->zgwService->method('getLogger')->willReturn($this->logger);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return AcController
	 */
	private function controller(): AcController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => 'T:' . $text);

		return new AcController('dossiq', $this->request, $this->zgwService, $l10n);
	}//end controller()

	/**
	 * Seed two stored consumers.
	 *
	 * @return void
	 */
	private function seedConsumers(): void {
		$this->mapper->consumers[] = new AcFakeConsumer('u-1', 'app-one', 'App one', ['superuser' => true, 'scopes' => []]);
		$this->mapper->consumers[] = new AcFakeConsumer(
			'u-2',
			'app-two',
			'App two',
			['superuser' => false, 'scopes' => [['component' => 'zrc']], 'clientIds' => ['extra-two']]
		);
	}//end seedConsumers()

	/**
	 * Index refuses a caller whose JWT does not verify.
	 *
	 * @return void
	 */
	public function testIndexReturnsTheAuthenticationRefusal(): void {
		$this->authError = new JSONResponse(['detail' => 'no'], Http::STATUS_FORBIDDEN);

		$this->assertSame($this->authError, $this->controller()->index());
		$this->assertSame([], $this->mapper->calls);
	}//end testIndexReturnsTheAuthenticationRefusal()

	/**
	 * Index answers 503 without a consumer mapper.
	 *
	 * @return void
	 */
	public function testIndexWithoutMapperIsUnavailable(): void {
		$this->mapperAvailable = false;

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['detail' => 'Consumer service not available'], $response->getData());
	}//end testIndexWithoutMapperIsUnavailable()

	/**
	 * Index lists every consumer as an applicatie.
	 *
	 * @return void
	 */
	public function testIndexListsEveryApplicatie(): void {
		$this->seedConsumers();

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'count' => 2,
				'next' => null,
				'previous' => null,
				'results' => [
					[
						'url' => 'https://host/autorisaties/v1/applicaties/u-1',
						'uuid' => 'u-1',
						'clientIds' => ['app-one'],
						'label' => 'App one',
						'heeftAlleAutorisaties' => true,
						'autorisaties' => [],
					],
					[
						'url' => 'https://host/autorisaties/v1/applicaties/u-2',
						'uuid' => 'u-2',
						'clientIds' => ['app-two', 'extra-two'],
						'label' => 'App two',
						'heeftAlleAutorisaties' => false,
						'autorisaties' => [['component' => 'zrc']],
					],
				],
			],
			$response->getData()
		);
		$this->assertSame([['findAll', []]], $this->mapper->calls);
	}//end testIndexListsEveryApplicatie()

	/**
	 * Index filters on the primary clientId by name.
	 *
	 * @return void
	 */
	public function testIndexFiltersOnThePrimaryClientId(): void {
		$this->seedConsumers();
		$this->params = ['clientId' => 'app-one', 'clientIds' => 'app-two'];

		$data = $this->controller()->index()->getData();

		$this->assertSame(1, $data['count']);
		$this->assertSame('u-1', $data['results'][0]['uuid']);
		$this->assertSame([['findAll', []], ['findAll', ['name' => 'app-one']]], $this->mapper->calls);
	}//end testIndexFiltersOnThePrimaryClientId()

	/**
	 * Index falls back to clientIds and then to the extra clientIds.
	 *
	 * @return void
	 */
	public function testIndexFallsBackToExtraClientIds(): void {
		$this->seedConsumers();
		$this->params = ['clientId' => '', 'clientIds' => 'extra-two'];

		$data = $this->controller()->index()->getData();

		$this->assertSame(1, $data['count']);
		$this->assertSame('u-2', $data['results'][0]['uuid']);
		$this->assertSame(
			[['findAll', []], ['findAll', ['name' => 'extra-two']], ['findAll', []]],
			$this->mapper->calls
		);
	}//end testIndexFallsBackToExtraClientIds()

	/**
	 * Index with an unknown filter answers an empty list.
	 *
	 * @return void
	 */
	public function testIndexWithUnknownClientIdIsEmpty(): void {
		$this->seedConsumers();
		$this->params = ['clientId' => 'nobody'];

		$this->assertSame(0, $this->controller()->index()->getData()['count']);
	}//end testIndexWithUnknownClientIdIsEmpty()

	/**
	 * Index logs and answers 500 when the mapper throws.
	 *
	 * @return void
	 */
	public function testIndexFailureIsLoggedAsServerError(): void {
		$this->mapper->throw = new RuntimeException('db gone');
		$this->logger->expects($this->once())->method('error')->with('AC list error: db gone');

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['detail' => 'Internal server error'], $response->getData());
	}//end testIndexFailureIsLoggedAsServerError()

	/**
	 * Show refuses an unauthenticated caller, delegates 'consumer' and maps one.
	 *
	 * @return void
	 */
	public function testShowOutcomes(): void {
		$this->authError = new JSONResponse(['detail' => 'no'], Http::STATUS_FORBIDDEN);
		$this->assertSame($this->authError, $this->controller()->show('u-1'));

		$this->authError = null;
		$this->seedConsumers();
		$this->assertSame(2, $this->controller()->show('consumer')->getData()['count']);

		$found = $this->controller()->show('u-2');
		$this->assertSame(Http::STATUS_OK, $found->getStatus());
		$this->assertSame(['app-two', 'extra-two'], $found->getData()['clientIds']);

		$missing = $this->controller()->show('u-9');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['detail' => 'Not found'], $missing->getData());

		$this->mapperAvailable = false;
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller()->show('u-1')->getStatus());
	}//end testShowOutcomes()

	/**
	 * Create refuses a consumer without ac.aanmaken.
	 *
	 * @return void
	 */
	public function testCreateRefusesWithoutScope(): void {
		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			[
				'type' => 'PermissionDenied',
				'code' => 'permission_denied',
				'title' => 'U heeft geen toestemming om autorisaties aan te maken.',
				'status' => Http::STATUS_FORBIDDEN,
				'detail' => 'Consumer caller-app heeft scope ac.aanmaken niet.',
			],
			$response->getData()
		);
		$this->assertSame([], $this->mapper->calls);
	}//end testCreateRefusesWithoutScope()

	/**
	 * Create refuses heeftAlleAutorisaties=true from a non-superuser.
	 *
	 * @return void
	 */
	public function testCreateRefusesSuperuserEscalation(): void {
		$this->heldScopes = ['ac.aanmaken'];
		$this->body = ['heeftAlleAutorisaties' => true, 'clientIds' => ['new-app']];

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			[
				'invalidParams' => [
					[
						'name' => 'heeftAlleAutorisaties',
						'code' => 'permission_denied',
						'reason' => 'Alleen superuser-consumers mogen heeftAlleAutorisaties op true zetten.',
					],
				],
			],
			$response->getData()
		);
		$this->assertSame([], $this->mapper->calls);
	}//end testCreateRefusesSuperuserEscalation()

	/**
	 * Create lets a superuser create a superuser applicatie.
	 *
	 * @return void
	 */
	public function testCreateAllowsSuperuserToGrantAll(): void {
		$this->heldScopes = ['ac.aanmaken', 'ac.superuser'];
		$this->body = [
			'heeftAlleAutorisaties' => true,
			'clientIds' => ['new-app', 'alias-a', 'alias-b'],
			'label' => 'New app',
			'secret' => 's3cret',
		];

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(
			[
				'name' => 'new-app',
				'description' => 'New app',
				'authorizationType' => 'jwt-zgw',
				'authorizationConfiguration' => [
					'superuser' => true,
					'scopes' => [],
					'algorithm' => 'HS256',
					'clientIds' => ['alias-a', 'alias-b'],
					'publicKey' => 's3cret',
				],
			],
			$this->mapper->created
		);
		$this->assertSame(['new-app', 'alias-a', 'alias-b'], $response->getData()['clientIds']);
		$this->assertTrue($response->getData()['heeftAlleAutorisaties']);
	}//end testCreateAllowsSuperuserToGrantAll()

	/**
	 * Create without clientIds names the consumer after its label.
	 *
	 * @return void
	 */
	public function testCreateWithoutClientIdsUsesTheLabel(): void {
		$this->heldScopes = ['ac.aanmaken'];
		$this->body = ['autorisaties' => [['component' => 'ac', 'scopes' => ['ac.lezen']]]];

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('unknown', $this->mapper->created['name']);
		$this->assertSame('', $this->mapper->created['description']);
		$this->assertFalse($this->mapper->created['authorizationConfiguration']['superuser']);
		$this->assertArrayNotHasKey('clientIds', $this->mapper->created['authorizationConfiguration']);
		$this->assertSame(['unknown'], $response->getData()['clientIds']);
		$this->assertSame([], $this->mapper->calls);
	}//end testCreateWithoutClientIdsUsesTheLabel()

	/**
	 * Consistency rule ac-002 in its spellings.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function consistencyCases(): array {
		$ambiguous = 'ambiguous-authorizations-specified';
		$missing = 'missing-authorizations';
		$one = [['component' => 'ac', 'scopes' => []]];

		return [
			'true with autorisaties' => [['heeftAlleAutorisaties' => true, 'autorisaties' => $one], $ambiguous],
			'"true" with autorisaties' => [['heeftAlleAutorisaties' => 'true', 'autorisaties' => $one], $ambiguous],
			'"1" with autorisaties' => [['heeftAlleAutorisaties' => '1', 'autorisaties' => $one], $ambiguous],
			'1 with autorisaties' => [['heeftAlleAutorisaties' => 1, 'autorisaties' => $one], $ambiguous],
			'true without autorisaties' => [['heeftAlleAutorisaties' => true, 'autorisaties' => []], null],
			'false with empty autorisaties' => [['heeftAlleAutorisaties' => false, 'autorisaties' => []], $missing],
			'"false" with empty' => [['heeftAlleAutorisaties' => 'false', 'autorisaties' => []], $missing],
			'"0" with empty' => [['heeftAlleAutorisaties' => '0', 'autorisaties' => []], $missing],
			'0 with empty' => [['heeftAlleAutorisaties' => 0, 'autorisaties' => []], $missing],
			'absent with empty' => [['autorisaties' => []], $missing],
			'false and no key' => [['heeftAlleAutorisaties' => false], null],
			'"yes" with empty' => [['heeftAlleAutorisaties' => 'yes', 'autorisaties' => []], null],
			'false with non-array' => [['heeftAlleAutorisaties' => false, 'autorisaties' => 'x'], null],
		];
	}//end consistencyCases()

	/**
	 * Create applies ac-002 before anything else is checked.
	 *
	 * @param array<string, mixed> $body The request body
	 * @param string|null          $code The expected refusal code, null when accepted
	 *
	 * @dataProvider consistencyCases
	 *
	 * @return void
	 */
	public function testCreateAppliesTheConsistencyRule(array $body, ?string $code): void {
		$this->heldScopes = ['ac.aanmaken', 'ac.superuser'];
		$this->body = $body;

		$response = $this->controller()->create();

		if ($code === null) {
			$this->assertNotSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			return;
		}

		$reasons = [
			'ambiguous-authorizations-specified' => 'T:When heeftAlleAutorisaties is true, autorisaties must not be specified. When heeftAlleAutorisaties is false, autorisaties must be specified.',
			'missing-authorizations' => 'T:When heeftAlleAutorisaties is false, autorisaties must be specified.',
		];
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['invalidParams' => [['name' => 'nonFieldErrors', 'code' => $code, 'reason' => $reasons[$code]]]],
			$response->getData()
		);
		$this->assertNull($this->mapper->created);
	}//end testCreateAppliesTheConsistencyRule()

	/**
	 * Create applies ac-003 per component, in entry and field order.
	 *
	 * @return void
	 */
	public function testCreateAppliesTheScopeRules(): void {
		$this->heldScopes = ['ac.aanmaken'];
		$this->body = [
			'autorisaties' => [
				['component' => 'zrc', 'scopes' => ['zaken.lezen']],
				['component' => 'zrc', 'scopes' => ['zaken.lezen'], 'caseType' => 'zt', 'maxVertrouwelijkheidaanduiding' => ''],
				['component' => 'drc', 'scopes' => [1, 'documenten.lezen']],
				['component' => 'drc', 'scopes' => ['documenten.lezen'], 'informatieobjecttype' => 'it', 'maxVertrouwelijkheidaanduiding' => 'geheim'],
				['component' => 'brc', 'scopes' => ['besluiten.lezen'], 'besluittype' => ''],
				['component' => 'brc', 'scopes' => ['besluiten.lezen'], 'besluittype' => 'bt'],
				['component' => 'zrc', 'scopes' => ['documenten.lezen']],
				['component' => 'drc', 'scopes' => ['zaken.lezen']],
				['component' => 'brc', 'scopes' => ['zaken.lezen']],
				['component' => 'nrc', 'scopes' => ['zaken.lezen', 'documenten.lezen', 'besluiten.lezen']],
				['scopes' => ['zaken.lezen']],
				['component' => 'zrc'],
			],
		];

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			[
				'invalidParams' => [
					[
						'name' => 'autorisaties.0.zaaktype',
						'code' => 'required',
						'reason' => 'T:zaaktype is required when a scope related to zaken is specified.',
					],
					[
						'name' => 'autorisaties.0.maxVertrouwelijkheidaanduiding',
						'code' => 'required',
						'reason' => 'T:maxVertrouwelijkheidaanduiding is required when a scope related to zaken is specified.',
					],
					[
						'name' => 'autorisaties.1.maxVertrouwelijkheidaanduiding',
						'code' => 'required',
						'reason' => 'T:maxVertrouwelijkheidaanduiding is required when a scope related to zaken is specified.',
					],
					[
						'name' => 'autorisaties.2.informatieobjecttype',
						'code' => 'required',
						'reason' => 'T:informatieobjecttype is required when a scope related to documenten is specified.',
					],
					[
						'name' => 'autorisaties.2.maxVertrouwelijkheidaanduiding',
						'code' => 'required',
						'reason' => 'T:maxVertrouwelijkheidaanduiding is required when a scope related to documenten is specified.',
					],
					[
						'name' => 'autorisaties.4.besluittype',
						'code' => 'required',
						'reason' => 'T:besluittype is required when a scope related to besluiten is specified.',
					],
				],
			],
			$response->getData()
		);
		$this->assertNull($this->mapper->created);
	}//end testCreateAppliesTheScopeRules()

	/**
	 * Create accepts complete autorisaties and a non-array autorisaties value.
	 *
	 * @return void
	 */
	public function testCreateAcceptsCompleteAutorisaties(): void {
		$this->heldScopes = ['ac.aanmaken'];
		$this->body = [
			'autorisaties' => [
				['component' => 'zrc', 'scopes' => ['zaken.lezen'], 'caseType' => 'zt', 'maxVertrouwelijkheidaanduiding' => 'openbaar'],
			],
		];
		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create()->getStatus());

		$this->body = ['heeftAlleAutorisaties' => false, 'autorisaties' => 'not-a-list'];
		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create()->getStatus());
	}//end testCreateAcceptsCompleteAutorisaties()

	/**
	 * Create applies ac-001: at most 100 clientIds, none already in use.
	 *
	 * @return void
	 */
	public function testCreateAppliesClientIdUniqueness(): void {
		$this->heldScopes = ['ac.aanmaken'];
		$this->seedConsumers();

		$this->body = ['clientIds' => array_map(fn (int $i) => 'id-' . $i, range(1, 101))];
		$tooMany = $this->controller()->create();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $tooMany->getStatus());
		$this->assertSame(
			[
				'invalidParams' => [
					[
						'name' => 'clientIds',
						'code' => 'max-items',
						'reason' => 'A maximum of 100 clientIds per applicatie is allowed.',
					],
				],
			],
			$tooMany->getData()
		);
		$this->assertSame([], $this->mapper->calls);

		$this->body = ['clientIds' => ['fresh', 'extra-two', 'app-one']];
		$taken = $this->controller()->create();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $taken->getStatus());
		$this->assertSame(
			[
				'invalidParams' => [
					[
						'name' => 'clientIds',
						'code' => 'clientId-exists',
						'reason' => 'clientId "app-one" is already used by another applicatie.',
					],
				],
			],
			$taken->getData()
		);
		$this->assertSame([['findAll', []]], $this->mapper->calls);

		$this->body = ['clientIds' => ['fresh', 'extra-two']];
		$this->assertSame(
			'clientId "extra-two" is already used by another applicatie.',
			$this->controller()->create()->getData()['invalidParams'][0]['reason']
		);

		$this->body = ['clientIds' => ['fresh']];
		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create()->getStatus());

		$this->body = ['clientIds' => 'not-a-list', 'label' => 'Lbl'];
		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create()->getStatus());
		$this->assertSame('Lbl', $this->mapper->created['name']);
	}//end testCreateAppliesClientIdUniqueness()

	/**
	 * Create without mapper is unavailable; a failing save is a 400 with its message.
	 *
	 * @return void
	 */
	public function testCreateFailures(): void {
		$this->heldScopes = ['ac.aanmaken'];
		$this->mapperAvailable = false;
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller()->create()->getStatus());

		$this->mapperAvailable = true;
		$this->mapper->throw = new RuntimeException('cannot save');
		$this->body = ['clientIds' => ['fresh']];
		$this->logger->expects($this->once())->method('error')->with('AC create error: cannot save');

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'cannot save'], $response->getData());
	}//end testCreateFailures()

	/**
	 * Update refuses without ac.bijwerken and for an unknown uuid.
	 *
	 * @return void
	 */
	public function testUpdateRefusals(): void {
		$this->seedConsumers();

		$refused = $this->controller()->update('u-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame('U heeft geen toestemming om autorisaties bij te werken.', $refused->getData()['title']);
		$this->assertSame('Consumer caller-app heeft scope ac.bijwerken niet.', $refused->getData()['detail']);

		$this->heldScopes = ['ac.bijwerken'];
		$missing = $this->controller()->update('u-9');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['detail' => 'Not found'], $missing->getData());

		$this->body = ['heeftAlleAutorisaties' => true];
		$escalation = $this->controller()->patch('u-2');
		$this->assertSame(Http::STATUS_FORBIDDEN, $escalation->getStatus());
		$this->assertSame('heeftAlleAutorisaties', $escalation->getData()['invalidParams'][0]['name']);
		$this->assertNull($this->mapper->updated);

		$this->mapperAvailable = false;
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller()->update('u-1')->getStatus());
	}//end testUpdateRefusals()

	/**
	 * Update writes only the four known consumer fields.
	 *
	 * @return void
	 */
	public function testUpdateAppliesOnlyKnownFields(): void {
		$this->seedConsumers();
		$this->heldScopes = ['ac.bijwerken', 'ac.superuser'];
		$this->body = ['clientIds' => ['renamed', 'alias'], 'label' => 'Renamed', 'heeftAlleAutorisaties' => true];

		$response = $this->controller()->update('u-2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$consumer = $this->mapper->updated;
		$this->assertSame('renamed', $consumer->name);
		$this->assertSame('Renamed', $consumer->description);
		$this->assertSame('jwt-zgw', $consumer->authorizationType);
		$this->assertSame('u-2', $consumer->uuid);
		$this->assertSame(
			['superuser' => true, 'scopes' => [], 'algorithm' => 'HS256', 'clientIds' => ['alias']],
			$consumer->authConfig
		);
		$this->assertSame(['renamed', 'alias'], $response->getData()['clientIds']);
	}//end testUpdateAppliesOnlyKnownFields()

	/**
	 * Update reports a failing save as a 400 with its message.
	 *
	 * @return void
	 */
	public function testUpdateFailureIsABadRequest(): void {
		$this->seedConsumers();
		$this->heldScopes = ['ac.bijwerken'];
		$this->mapper->failUpdate = new RuntimeException('locked');
		$this->logger->expects($this->once())->method('error')->with('AC update error: locked');

		$response = $this->controller()->update('u-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['detail' => 'locked'], $response->getData());
	}//end testUpdateFailureIsABadRequest()

	/**
	 * Destroy refuses without ac.verwijderen and deletes with it.
	 *
	 * @return void
	 */
	public function testDestroyOutcomes(): void {
		$this->seedConsumers();

		$refused = $this->controller()->destroy('u-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame('U heeft geen toestemming om autorisaties te verwijderen.', $refused->getData()['title']);
		$this->assertNull($this->mapper->deleted);

		$this->heldScopes = ['ac.verwijderen'];
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->destroy('u-9')->getStatus());

		$deleted = $this->controller()->destroy('u-1');
		$this->assertSame(Http::STATUS_NO_CONTENT, $deleted->getStatus());
		$this->assertSame('u-1', $this->mapper->deleted->uuid);
	}//end testDestroyOutcomes()

	/**
	 * A refused write with an unreadable token names the caller 'onbekend'.
	 *
	 * @return void
	 */
	public function testRefusalWithUnreadableTokenNamesNobody(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer not-a-jwt');
		$l10n = $this->createMock(IL10N::class);
		$controller = new AcController('dossiq', $request, $this->zgwService, $l10n);

		$this->assertSame(
			'Consumer onbekend heeft scope ac.verwijderen niet.',
			$controller->destroy('u-1')->getData()['detail']
		);
	}//end testRefusalWithUnreadableTokenNamesNobody()
}//end class

/**
 * In-memory consumer mapper.
 */
class AcFakeConsumerMapper {

	/**
	 * Stored consumers.
	 *
	 * @var array<int, AcFakeConsumer>
	 */
	public array $consumers = [];

	/**
	 * Every findAll call with its filters.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * The data the last createFromArray received.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $created = null;

	/**
	 * The last updated consumer.
	 *
	 * @var AcFakeConsumer|null
	 */
	public ?AcFakeConsumer $updated = null;

	/**
	 * The last deleted consumer.
	 *
	 * @var AcFakeConsumer|null
	 */
	public ?AcFakeConsumer $deleted = null;

	/**
	 * Thrown by findAll and createFromArray when set.
	 *
	 * @var RuntimeException|null
	 */
	public ?RuntimeException $throw = null;

	/**
	 * Thrown by update when set.
	 *
	 * @var RuntimeException|null
	 */
	public ?RuntimeException $failUpdate = null;

	/**
	 * Find consumers matching the filters.
	 *
	 * @param array<string, mixed> $filters The filters
	 *
	 * @return array<int, AcFakeConsumer>
	 */
	public function findAll(array $filters = []): array {
		if ($this->throw !== null) {
			throw $this->throw;
		}

		$uuidLookup = isset($filters['uuid']);
		if ($uuidLookup === false) {
			$this->calls[] = ['findAll', $filters];
		}

		return array_values(
			array_filter(
				$this->consumers,
				function (AcFakeConsumer $consumer) use ($filters): bool {
					foreach ($filters as $field => $value) {
						if ($consumer->$field !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end findAll()

	/**
	 * Create a consumer from data.
	 *
	 * @param array<string, mixed> $object The consumer data
	 *
	 * @return AcFakeConsumer
	 */
	public function createFromArray(array $object): AcFakeConsumer {
		if ($this->throw !== null) {
			throw $this->throw;
		}

		$this->created = $object;

		return new AcFakeConsumer('new-uuid', $object['name'], $object['description'], $object['authorizationConfiguration']);
	}//end createFromArray()

	/**
	 * Update a consumer.
	 *
	 * @param AcFakeConsumer $entity The consumer
	 *
	 * @return AcFakeConsumer
	 */
	public function update(AcFakeConsumer $entity): AcFakeConsumer {
		if ($this->failUpdate !== null) {
			throw $this->failUpdate;
		}

		$this->updated = $entity;

		return $entity;
	}//end update()

	/**
	 * Delete a consumer.
	 *
	 * @param AcFakeConsumer $entity The consumer
	 *
	 * @return void
	 */
	public function delete(AcFakeConsumer $entity): void {
		$this->deleted = $entity;
	}//end delete()
}//end class

/**
 * A stored API consumer.
 */
class AcFakeConsumer {

	/**
	 * The authorization type.
	 *
	 * @var string
	 */
	public string $authorizationType = 'jwt-zgw';

	/**
	 * Build a consumer.
	 *
	 * @param string               $uuid        The uuid
	 * @param string               $name        The primary clientId
	 * @param string               $description The label
	 * @param array<string, mixed> $authConfig  The authorization configuration
	 */
	public function __construct(
		public string $uuid,
		public string $name,
		public string $description,
		public array $authConfig,
	) {
	}//end __construct()

	/**
	 * Serialise the consumer.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return ['uuid' => $this->uuid, 'name' => $this->name, 'description' => $this->description];
	}//end jsonSerialize()

	/**
	 * The authorization configuration.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getAuthorizationConfiguration(): ?array {
		return $this->authConfig;
	}//end getAuthorizationConfiguration()

	/**
	 * Set the name.
	 *
	 * @param string $name The name
	 *
	 * @return void
	 */
	public function setName(string $name): void {
		$this->name = $name;
	}//end setName()

	/**
	 * Set the description.
	 *
	 * @param string $description The description
	 *
	 * @return void
	 */
	public function setDescription(string $description): void {
		$this->description = $description;
	}//end setDescription()

	/**
	 * Set the authorization type.
	 *
	 * @param string $type The type
	 *
	 * @return void
	 */
	public function setAuthorizationType(string $type): void {
		$this->authorizationType = $type;
	}//end setAuthorizationType()

	/**
	 * Set the authorization configuration.
	 *
	 * @param array<string, mixed> $config The configuration
	 *
	 * @return void
	 */
	public function setAuthorizationConfiguration(array $config): void {
		$this->authConfig = $config;
	}//end setAuthorizationConfiguration()

	/**
	 * Set the uuid; never reached through an update body.
	 *
	 * @param string $uuid The uuid
	 *
	 * @return void
	 */
	public function setUuid(string $uuid): void {
		$this->uuid = $uuid;
	}//end setUuid()
}//end class
