<?php

/**
 * What ZrcController does with relevanteAndereZaken on the way out and on the way in.
 *
 * Characterisation written against the unsplit methods and run green on them
 * before they were split.
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\ZrcController
 */
class ZrcRelatedCasesTest extends TestCase {

	private const UUID_A = 'aaaaaaaa-1111-2222-3333-444444444444';
	private const UUID_B = 'bbbbbbbb-1111-2222-3333-444444444444';

	/**
	 * The case ids the relation service was asked to list.
	 *
	 * @var array<int, string>
	 */
	private array $listed = [];

	/**
	 * The relations added, as [caseId, targetId, nature].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $added = [];

	/**
	 * How many times the base URL was built.
	 *
	 * @var integer
	 */
	private int $baseUrls = 0;

	/**
	 * The controller over a relation service answering from a map.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $relations Relations by case id.
	 *
	 * @return ZrcController
	 */
	private function controller(array $relations): ZrcController {
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('buildBaseUrl')->willReturnCallback(
			function (): string {
				$this->baseUrls++;
				return 'https://nc/zaken/api/v1/zaken';
			}
		);

		$service = $this->createMock(CaseRelationService::class);
		$service->method('listRelations')->willReturnCallback(
			function (string $caseId) use ($relations): array {
				$this->listed[] = $caseId;
				return ($relations[$caseId] ?? []);
			}
		);
		$service->method('addRelation')->willReturnCallback(
			function (string $caseId, string $targetId, string $natureRelationship): array {
				$this->added[] = [$caseId, $targetId, $natureRelationship];
				if ($targetId === self::UUID_B) {
					return ['ok' => false, 'reason' => 'access_denied'];
				}

				return ['ok' => false, 'reason' => 'duplicate'];
			}
		);

		return new ZrcController(
			request: $this->createMock(IRequest::class),
			zgwService: $zgw,
			l10n: $this->createMock(IL10N::class),
			caseRelationService: $service,
			statusEffects: $this->createMock(ZrcStatusEffects::class),
			joinHoming: $this->createMock(DocumentJoinHoming::class),
		);
	}//end controller()

	/**
	 * Call a private method of the controller.
	 *
	 * @param ZrcController        $controller The controller.
	 * @param string               $method     The method.
	 * @param array<string, mixed> $args       Named arguments.
	 *
	 * @return mixed
	 */
	private function call(ZrcController $controller, string $method, array $args): mixed {
		$reflection = new \ReflectionMethod($controller, $method);
		return $reflection->invokeArgs($controller, $args);
	}//end call()

	/**
	 * Outbound: the case uuid comes from the id, else the self URL, else stays verbatim; only complete relations are emitted.
	 *
	 * @return void
	 */
	public function testOutboundRelationsNameTheirTargetsByUrl(): void {
		$controller = $this->controller(
			relations: [
				strtoupper(self::UUID_A) => [
					['caseId' => 'target-1', 'aardRelatie' => 'vervolg'],
					['caseId' => '', 'aardRelatie' => 'vervolg'],
					['caseId' => 'target-2'],
					['caseId' => 'target-3', 'aardRelatie' => 'bijdrage'],
				],
				'plain-id' => [['caseId' => 'target-4', 'aardRelatie' => 'onderwerp']],
			]
		);

		$this->assertSame(
			[
				['url' => 'https://nc/zaken/api/v1/zaken/target-1', 'aardRelatie' => 'vervolg'],
				['url' => 'https://nc/zaken/api/v1/zaken/target-3', 'aardRelatie' => 'bijdrage'],
			],
			$this->call($controller, 'buildRelevanteAndereCases', ['caseData' => ['uuid' => 'x-' . strtoupper(self::UUID_A) . '-y']])
		);
		$this->assertSame(
			[['url' => 'https://nc/zaken/api/v1/zaken/target-4', 'aardRelatie' => 'onderwerp']],
			$this->call($controller, 'buildRelevanteAndereCases', ['caseData' => ['identificatie' => 'plain-id', 'url' => 'https://nc/zaken/no-uuid']])
		);
		$this->assertSame([], $this->call($controller, 'buildRelevanteAndereCases', ['caseData' => ['url' => 'https://nc/zaken/' . self::UUID_B]]));
		$this->assertSame([], $this->call($controller, 'buildRelevanteAndereCases', ['caseData' => []]));

		$this->assertSame([strtoupper(self::UUID_A), 'plain-id', self::UUID_B], $this->listed);
		$this->assertSame(2, $this->baseUrls);
	}//end testOutboundRelationsNameTheirTargetsByUrl()

	/**
	 * A list response gets the relations on every result that is an object, and anything else is left alone.
	 *
	 * @return void
	 */
	public function testAListResponseIsEnrichedPerResult(): void {
		$controller = $this->controller(relations: [self::UUID_A => [['caseId' => 't', 'aardRelatie' => 'vervolg']]]);

		$list = new JSONResponse(['count' => 2, 'results' => [['uuid' => self::UUID_A], 'not a case', ['uuid' => '']]]);
		$this->call($controller, 'enrichCasesListRelevanteAndereCases', ['response' => $list]);
		$this->assertSame(
			[
				'count' => 2,
				'results' => [
					['uuid' => self::UUID_A, 'relevanteAndereZaken' => [['url' => 'https://nc/zaken/api/v1/zaken/t', 'aardRelatie' => 'vervolg']]],
					'not a case',
					['uuid' => '', 'relevanteAndereZaken' => []],
				],
			],
			$list->getData()
		);

		foreach ([['results' => 'x'], ['count' => 0], 'text'] as $data) {
			$response = new JSONResponse($data);
			$this->assertSame($response, $this->call($controller, 'enrichCasesListRelevanteAndereCases', ['response' => $response]));
			$this->assertSame($data, $response->getData());
		}
	}//end testAListResponseIsEnrichedPerResult()

	/**
	 * Inbound: nothing to do without relations or a case; entries are added in order until one names no usable zaak.
	 *
	 * @return void
	 */
	public function testInboundRelationsStopAtTheFirstUnknownZaak(): void {
		$controller = $this->controller(relations: []);

		$this->assertNull($this->call($controller, 'applyInboundRelevanteAndereCases', ['caseUuid' => 'c', 'body' => []]));
		$this->assertNull($this->call($controller, 'applyInboundRelevanteAndereCases', ['caseUuid' => 'c', 'body' => ['relevanteAndereZaken' => 'x']]));
		$this->assertNull($this->call($controller, 'applyInboundRelevanteAndereCases', ['caseUuid' => '', 'body' => ['relevanteAndereZaken' => [['url' => 'https://z/' . self::UUID_A]]]]));
		$this->assertSame([], $this->added);

		$this->assertNull(
			$this->call($controller, 'applyInboundRelevanteAndereCases', ['caseUuid' => 'c', 'body' => ['relevanteAndereZaken' => ['skip', ['url' => 'https://z/' . self::UUID_A, 'aardRelatie' => 'vervolg']]]])
		);

		$refusal = $this->call(
			$controller,
			'applyInboundRelevanteAndereCases',
			['caseUuid' => 'c', 'body' => ['relevanteAndereZaken' => ['k' => ['url' => 'https://z/' . self::UUID_B], 'l' => ['url' => 'https://z/' . self::UUID_A]]]]
		);
		$this->assertSame(400, $refusal->getStatus());
		$this->assertSame('relevanteAndereZaken.k.url', $refusal->getData()['invalidParams'][0]['name']);
		$this->assertSame(
			[['c', self::UUID_A, 'vervolg'], ['c', self::UUID_B, '']],
			$this->added
		);
	}//end testInboundRelationsStopAtTheFirstUnknownZaak()
}//end class
