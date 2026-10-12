<?php

/**
 * Who may read which zaak through the ZRC (zrc-006a, zrc-006b).
 *
 * Characterisation for method-decomposition slice 6c: written against
 * checkCaseReadAccess() and filterCasesByAuthorisation() before they were
 * split, and run green on both versions.
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
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ZrcReadAccessStore {
	/** @var mixed The find() answer, or a Throwable to throw. */
	public mixed $found = ['confidentiality' => 'openbaar'];

	public function find(int|string $id, mixed ...$args): mixed {
		if ($this->found instanceof \Throwable) {
			throw $this->found;
		}

		return $this->found;
	}
}//end class

final class ZrcCaseReadAccessTest extends TestCase {
	private const CASE_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	private ZrcReadAccessStore $store;

	/** @var array<int, array<string, mixed>>|null */
	private ?array $autorisaties = null;

	private bool $mapped = true;

	private bool $showReached = false;

	private mixed $indexData = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store = new ZrcReadAccessStore();
		$this->autorisaties = null;
		$this->mapped = true;
		$this->showReached = false;
	}

	private function controller(): ZrcController {
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturn(null);
		$zgw->method('getConsumerAuthorisaties')->willReturnCallback(fn (): ?array => $this->autorisaties);
		$zgw->method('getObjectService')->willReturn($this->store);
		$zgw->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		$zgw->method('loadMappingConfig')->willReturnCallback(
			fn (): ?array => $this->mapped === true ? ['sourceRegister' => '1', 'sourceSchema' => '2'] : null
		);
		$zgw->method('handleShow')->willReturnCallback(
			function (): JSONResponse {
				$this->showReached = true;
				return new JSONResponse(['uuid' => ''], Http::STATUS_OK);
			}
		);
		$zgw->method('handleIndex')->willReturnCallback(fn (): JSONResponse => new JSONResponse($this->indexData, Http::STATUS_OK));

		$relations = $this->createMock(CaseRelationService::class);
		$relations->method('listRelations')->willReturn([]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ZrcController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			zgwService: $zgw,
			l10n: $l10n,
			caseRelationService: $relations,
			statusEffects: $this->createMock(ZrcStatusEffects::class),
			joinHoming: $this->createMock(DocumentJoinHoming::class),
		);
	}

	private function lezen(?string $max=null, string $key='maxVertrouwelijkheidaanduiding'): array {
		$auth = ['scopes' => ['zaken.lezen']];
		if ($max !== null) {
			$auth[$key] = $max;
		}

		return $auth;
	}

	private function show(): JSONResponse {
		return $this->controller()->show(resource: 'zaken', uuid: self::CASE_UUID);
	}

	public function testAnUnrestrictedConsumerReadsEveryZaak(): void {
		$this->assertSame(Http::STATUS_OK, $this->show()->getStatus());
		$this->assertTrue($this->showReached);
	}

	public function testWithoutZakenLezenTheZaakIsRefused(): void {
		$this->autorisaties = [['scopes' => ['zaken.aanmaken']]];

		$response = $this->show();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('permission_denied', $response->getData()['code']);
		$this->assertFalse($this->showReached);
	}

	public function testAZaakAboveTheConsumersLevelIsRefused(): void {
		$this->store->found = ['confidentiality' => 'geheim'];
		$this->autorisaties = [$this->lezen(max: 'openbaar')];

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->show()->getStatus());
	}

	public function testOneAuthorisationThatReachesTheLevelIsEnough(): void {
		$this->store->found = ['vertrouwelijkheidaanduiding' => 'geheim'];
		$this->autorisaties = [$this->lezen(max: 'openbaar'), ['scopes' => ['zaken.bijwerken'], 'maxVertrouwelijkheidaanduiding' => 'zeer_geheim'], $this->lezen(max: 'geheim', key: 'max_vertrouwelijkheidaanduiding')];

		$this->assertSame(Http::STATUS_OK, $this->show()->getStatus());
	}

	public function testUnknownLevelsReadAsOpenForTheZaakAndUnlimitedForTheConsumer(): void {
		$this->store->found = ['confidentiality' => 'nonsense'];
		$this->autorisaties = [$this->lezen(max: 'openbaar')];
		$this->assertSame(Http::STATUS_OK, $this->show()->getStatus());

		$this->store->found = ['confidentiality' => 'zeer_geheim'];
		$this->autorisaties = [$this->lezen(max: 'unheard-of')];
		$this->assertSame(Http::STATUS_OK, $this->show()->getStatus());

		$this->autorisaties = [$this->lezen()];
		$this->assertSame(Http::STATUS_OK, $this->show()->getStatus());
	}

	public function testNoMappingLetsTheReadThrough(): void {
		$this->autorisaties = [$this->lezen(max: 'openbaar')];
		$this->mapped = false;

		$this->assertSame(Http::STATUS_OK, $this->show()->getStatus());
	}

	public function testAFailedLookupDeniesTheRead(): void {
		$this->autorisaties = [$this->lezen(max: 'geheim')];

		$this->store->found = new \InvalidArgumentException('no such zaak');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->show()->getStatus());

		$this->store->found = new \RuntimeException('store down');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->show()->getStatus());
	}

	public function testTheListKeepsOnlyZakenWithinTheConsumersLevel(): void {
		$this->autorisaties = [$this->lezen(max: 'intern'), ['scopes' => ['zaken.bijwerken']]];
		$this->indexData = [
			'count' => 4,
			'results' => [
				['url' => 'a', 'vertrouwelijkheidaanduiding' => 'openbaar'],
				['url' => 'b', 'vertrouwelijkheidaanduiding' => 'geheim'],
				['url' => 'c'],
				['url' => 'd', 'vertrouwelijkheidaanduiding' => 'intern', 'confidentiality' => 'geheim'],
			],
		];

		$data = $this->controller()->index(resource: 'zaken')->getData();

		$this->assertSame(3, $data['count']);
		$this->assertSame(['a', 'c', 'd'], array_column($data['results'], 'url'));
	}

	public function testWithoutZakenLezenTheListIsEmpty(): void {
		$this->autorisaties = [['scopes' => ['zaken.aanmaken']]];
		$this->indexData = ['count' => 1, 'results' => [['url' => 'a']], 'next' => null];

		$data = $this->controller()->index(resource: 'zaken')->getData();

		$this->assertSame(['count' => 0, 'results' => [], 'next' => null], $data);
	}

	public function testAnUnrestrictedOrShapelessListIsLeftAlone(): void {
		$this->indexData = ['count' => 1, 'results' => [['url' => 'a', 'vertrouwelijkheidaanduiding' => 'zeer_geheim']]];
		$this->assertSame(1, $this->controller()->index(resource: 'zaken')->getData()['count']);

		$this->autorisaties = [$this->lezen(max: 'openbaar')];
		$this->indexData = ['count' => 7];
		$this->assertSame(['count' => 7], $this->controller()->index(resource: 'zaken')->getData());
	}
}//end class
