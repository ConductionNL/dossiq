<?php

/**
 * What ZrcController::create(), update() and patch() do around the shared ZGW handlers.
 *
 * Characterisation for method-decomposition slice 6c: written against the
 * unsplit methods and run green on them before they were split.
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

use OCA\Dossiq\Controller\ZrcController;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Zaakdossier\DocumentJoinHoming;
use OCA\Dossiq\Service\Zgw\ZrcStatusEffects;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ZrcWriteStore {
	/** @var array<int, array<string, mixed>> */
	public array $saved = [];

	public function saveObject(mixed $register=null, mixed $schema=null, array $object=[], ?string $uuid=null): array {
		$object['id'] = 'new-' . (count($this->saved) + 1);
		$this->saved[] = $object;
		return $object;
	}
}//end class

final class ZrcWritePathTest extends TestCase {
	private const CASE_URL = 'https://nc/zaken/api/v1/zaken/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	private ZrcWriteStore $store;

	/** @var array<string, bool> */
	private array $scopes = [];

	private array $body = [];

	private array $rule = ['valid' => true];

	private array $inbound = ['mapped' => true];

	private ?string $homingRefusal = null;

	/** @var array<int, string> */
	private array $calls = [];

	/** @var array<int, array<int, mixed>> */
	private array $updates = [];

	private JSONResponse $updateResponse;

	/** @var ZrcStatusEffects&MockObject */
	private ZrcStatusEffects&MockObject $effects;

	/** @var array<int, array<string, mixed>> */
	private array $relationsAdded = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store = new ZrcWriteStore();
		$this->scopes = ['zaken.aanmaken' => true, 'zaken.bijwerken' => true, 'zaken.heropenen' => true];
		$this->body = [];
		$this->rule = ['valid' => true];
		$this->inbound = ['mapped' => true];
		$this->homingRefusal = null;
		$this->calls = [];
		$this->updates = [];
		$this->updateResponse = new JSONResponse(['url' => self::CASE_URL], Http::STATUS_OK);
		$this->relationsAdded = [];
		$this->effects = $this->createMock(ZrcStatusEffects::class);
	}

	private function controller(): ZrcController {
		$rules = $this->createMock(ZgwBusinessRulesService::class);
		$rules->method('validate')->willReturnCallback(
			fn (...$args): array => $this->rule + ['enrichedBody' => $args['body'] ?? $args[3], 'status' => 400]
		);

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturn(null);
		$zgw->method('resolvePathUuid')->willReturnArgument(1);
		$zgw->method('consumerHasScope')->willReturnCallback(fn ($r, $c, string $scope): bool => ($this->scopes[$scope] ?? false));
		$zgw->method('getObjectService')->willReturn($this->store);
		$zgw->method('getZgwMappingService')->willReturn($this->createMock(ZgwMappingService::class));
		$zgw->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		$zgw->method('loadMappingConfig')->willReturnCallback(
			fn ($api, string $resource): ?array => ['sourceRegister' => '1', 'sourceSchema' => $resource]
		);
		$zgw->method('getRequestBody')->willReturnCallback(fn (): array => $this->body);
		$zgw->method('resolveZaakClosedFromBody')->willReturn(null);
		$zgw->method('getBusinessRulesService')->willReturn($rules);
		$zgw->method('buildValidationError')->willReturn(['detail' => 'rule refused']);
		$zgw->method('createInboundMapping')->willReturn(new \stdClass());
		$zgw->method('createOutboundMapping')->willReturn(new \stdClass());
		$zgw->method('applyInboundMapping')->willReturnCallback(fn () => $this->inbound);
		$zgw->method('applyOutboundMapping')->willReturnCallback(fn (array $objectData): array => ['out' => $objectData['id'] ?? '']);
		$zgw->method('buildBaseUrl')->willReturn('https://nc/zaken/api/v1/x');
		$zgw->method('publishNotification')->willReturnCallback(
			function (string $api, string $resource, string $url, string $action): void {
				$this->calls[] = $action . ' ' . $url;
			}
		);
		$zgw->method('handleUpdate')->willReturnCallback(
			function (...$args): JSONResponse {
				$this->updates[] = $args;
				return $this->updateResponse;
			}
		);

		$homing = $this->createMock(DocumentJoinHoming::class);
		$homing->method('refusal')->willReturnCallback(fn (): ?string => $this->homingRefusal);
		$homing->method('home')->willReturnCallback(
			function (string $caseUrl, string $informatieobjectUrl): bool {
				$this->calls[] = 'home ' . $caseUrl . ' ' . $informatieobjectUrl;
				return true;
			}
		);

		$relations = $this->createMock(CaseRelationService::class);
		$relations->method('listRelations')->willReturn([]);
		$relations->method('addRelation')->willReturnCallback(
			function (string $caseId, string $targetId, string $natureRelationship): array {
				$this->relationsAdded[] = [$caseId, $targetId, $natureRelationship];
				return ['ok' => $targetId !== 'dddddddd-0000-0000-0000-000000000000', 'reason' => 'access_denied'];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ZrcController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			zgwService: $zgw,
			l10n: $l10n,
			caseRelationService: $relations,
			statusEffects: $this->effects,
			joinHoming: $homing,
		);
	}

	public function testCreatingAZaakNeedsAanmakenAndOtherResourcesNeedBijwerken(): void {
		$this->scopes['zaken.aanmaken'] = false;
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->create(resource: 'zaken')->getStatus());
		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create(resource: 'rollen')->getStatus());

		$this->scopes['zaken.bijwerken'] = false;
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->create(resource: 'rollen')->getStatus());
		$this->assertSame([], array_slice($this->store->saved, 1));
	}

	public function testARuleRefusalAnswersWithItsStatusAndSavesNothing(): void {
		$this->rule = ['valid' => false];

		$response = $this->controller()->create(resource: 'zaken');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['detail' => 'rule refused'], $response->getData());
		$this->assertSame([], $this->store->saved);
	}

	public function testACreatedZaakIsSavedMappedAndAnnounced(): void {
		$response = $this->controller()->create(resource: 'zaken');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['out' => 'new-1'], $response->getData());
		$this->assertSame(['create https://nc/zaken/api/v1/x/new-1'], $this->calls);
	}

	public function testAZaakNamingAnUnknownRelatedZaakIsRefusedAfterSaving(): void {
		$this->body = ['relevanteAndereZaken' => [['url' => 'https://elsewhere/zaken/no-uuid', 'aardRelatie' => 'vervolg']]];

		$response = $this->controller()->create(resource: 'zaken');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('relevanteAndereZaken.0.url', $response->getData()['invalidParams'][0]['name']);
		$this->assertCount(1, $this->store->saved);
		$this->assertSame([], $this->calls);
	}

	public function testAStatusRunsTheReopenGateTheUsageRightsCheckAndTheEffect(): void {
		$this->body = ['case' => self::CASE_URL, 'statustype' => 'st'];
		$this->effects->method('isReopenAttempt')->willReturn(true);
		$this->effects->method('unsetUsageRightsRefusal')->willReturn(null);
		$this->effects->expects($this->once())->method('applyStatusEffect')->with($this->body, ['mapped' => true, 'id' => 'new-1']);

		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create(resource: 'statussen')->getStatus());
	}

	public function testAReopenWithoutTheHeropenenScopeIsRefused(): void {
		$this->scopes['zaken.heropenen'] = false;
		$this->effects->method('isReopenAttempt')->willReturn(true);
		$this->effects->expects($this->never())->method('applyStatusEffect');

		$response = $this->controller()->create(resource: 'statussen');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->store->saved);
	}

	public function testAnEindstatusWithUnsetUsageRightsIs400(): void {
		$this->effects->method('isReopenAttempt')->willReturn(false);
		$this->effects->method('unsetUsageRightsRefusal')->willReturn(['code' => 'indicatiegebruiksrecht-unset']);

		$response = $this->controller()->create(resource: 'statussen');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['code' => 'indicatiegebruiksrecht-unset'], $response->getData());
		$this->assertSame([], $this->store->saved);
	}

	public function testAResultDerivesTheArchiveParameters(): void {
		$this->body = ['case' => self::CASE_URL];
		$this->effects->expects($this->once())->method('applyResultEffect')->with($this->body);

		$this->controller()->create(resource: 'resultaten');
	}

	public function testAJoinIsRefusedForACaseWithoutAFolder(): void {
		$this->homingRefusal = 'This case has no folder.';

		$response = $this->controller()->create(resource: 'zaakinformatieobjecten');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['detail' => 'This case has no folder.'], $response->getData());
		$this->assertSame([], $this->store->saved);
	}

	public function testAJoinIsEnrichedAndHomesTheFile(): void {
		$this->body = ['zaak' => self::CASE_URL, 'informatieobject' => 'https://nc/documenten/io-1'];

		$response = $this->controller()->create(resource: 'zaakinformatieobjecten');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('Hoort bij, omgekeerd: kent', $response->getData()['natureRelationshipDisplay']);
		$this->assertContains('home ' . self::CASE_URL . ' https://nc/documenten/io-1', $this->calls);
	}

	public function testUpdateAndPatchNeedBijwerken(): void {
		$this->scopes['zaken.bijwerken'] = false;

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->update(resource: 'zaken', uuid: 'u')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->patch(resource: 'zaken', uuid: 'u')->getStatus());
		$this->assertSame([], $this->updates);
	}

	public function testABadCommunicatiekanaalIsRefusedBeforeTheUpdate(): void {
		$cases = [
			'not a url' => 'bad-url',
			'https://klant.nl/api/v1/kanalen/' => 'invalid-resource',
			'https://klant.nl/api/v1/kanalen/1234abcd-12' => 'bad-url',
		];
		foreach ($cases as $url => $code) {
			$this->body = ['communicatiekanaal' => $url];
			$response = $this->controller()->patch(resource: 'zaken', uuid: 'u');
			$this->assertSame($code, $response->getData()['invalidParams'][0]['code'], $url);
		}

		$this->body = ['communicatiekanaal' => 'https://klant.nl/api/v1/kanalen/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];
		$this->assertSame(Http::STATUS_OK, $this->controller()->update(resource: 'zaken', uuid: 'u')->getStatus());
		$this->assertCount(1, $this->updates);
	}

	public function testUpdateAndPatchHandTheirFlagToTheSharedHandlerAndEnrichTheAnswer(): void {
		$update = $this->controller()->update(resource: 'zaken', uuid: 'u1');
		$this->updateResponse = new JSONResponse(['url' => 'zio'], Http::STATUS_OK);
		$patch = $this->controller()->patch(resource: 'zaakinformatieobjecten', uuid: 'u2');

		// The store cannot find the existing zaak, so the closed-state lookup fails closed: closed, no geforceerd.
		$this->assertSame(['u1', false, null, true, false], [$this->updates[0][3], $this->updates[0][4], $this->updates[0][5], $this->updates[0][6], $this->updates[0][7]]);
		$this->assertSame(['u2', true], [$this->updates[1][3], $this->updates[1][4]]);
		$this->assertSame([], $update->getData()['relevanteAndereZaken']);
		$this->assertSame('Hoort bij, omgekeerd: kent', $patch->getData()['natureRelationshipDisplay']);
	}

	public function testAnUpdateNamingAnUnknownRelatedZaakIsRefused(): void {
		$this->body = ['relevanteAndereZaken' => ['skip', ['url' => ''], ['url' => 'https://nc/zaken/dddddddd-0000-0000-0000-000000000000']]];

		$response = $this->controller()->update(resource: 'zaken', uuid: 'u1');

		$this->assertSame('relevanteAndereZaken.2.url', $response->getData()['invalidParams'][0]['name']);
		$this->assertSame([['u1', 'dddddddd-0000-0000-0000-000000000000', '']], $this->relationsAdded);
	}

	public function testAFailedUpdateIsReturnedAsItCame(): void {
		$this->updateResponse = new JSONResponse(['detail' => 'nope'], Http::STATUS_CONFLICT);

		$this->assertSame(['detail' => 'nope'], $this->controller()->patch(resource: 'zaken', uuid: 'u')->getData());
	}
}//end class
