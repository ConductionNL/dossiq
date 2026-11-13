<?php

/**
 * What ZrcController answers for the besluiten of a zaak, and how it empties a list a consumer may not read.
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
use Psr\Log\LoggerInterface;

/**
 * A store that records the decision search and answers its hits.
 */
class ZrcCaseListsStore {
	/**
	 * Every query built and searched, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $calls = [];

	/**
	 * The hits, or a Throwable to throw.
	 *
	 * @var mixed
	 */
	public mixed $hits = [];

	/**
	 * Build a query.
	 *
	 * @param array<string, mixed> $requestParams The parameters.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
		$this->calls[] = ['build', $requestParams, $register, $schema];
		return ['q' => $requestParams];
	}//end buildSearchQuery()

	/**
	 * Search.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed>
	 */
	public function searchObjectsPaginated(array $query): array {
		$this->calls[] = ['search', $query];
		if ($this->hits instanceof \Throwable) {
			throw $this->hits;
		}

		return ['results' => $this->hits];
	}//end searchObjectsPaginated()
}//end class

/**
 * @covers \OCA\Dossiq\Controller\ZrcController
 */
class ZrcCaseListsTest extends TestCase {

	/**
	 * Error lines logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * The consumer's authorisaties.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $autorisaties = null;

	/**
	 * The controller over a store.
	 *
	 * @param ZrcCaseListsStore $store The store.
	 *
	 * @return ZrcController
	 */
	private function controller(ZrcCaseListsStore $store): ZrcController {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturn(null);
		$zgw->method('getObjectService')->willReturn($store);
		$zgw->method('getLogger')->willReturn($logger);
		$zgw->method('getConsumerAuthorisaties')->willReturnCallback(fn (): ?array => $this->autorisaties);
		$zgw->method('loadMappingConfig')->willReturn(['sourceRegister' => '4', 'sourceSchema' => '8']);
		$zgw->method('buildBaseUrl')->willReturn('https://nc/besluiten');
		$zgw->method('createOutboundMapping')->willReturn(new \stdClass());
		$zgw->method('applyOutboundMapping')->willReturnCallback(
			fn (array $objectData, $mapping, array $mappingConfig, string $baseUrl): array => ['url' => $baseUrl . '/' . ($objectData['id'] ?? '?')]
		);

		return new ZrcController(
			request: $this->createMock(IRequest::class),
			zgwService: $zgw,
			l10n: $this->createMock(IL10N::class),
			caseRelationService: $this->createMock(CaseRelationService::class),
			statusEffects: $this->createMock(ZrcStatusEffects::class),
			joinHoming: $this->createMock(DocumentJoinHoming::class),
		);
	}//end controller()

	/**
	 * The besluiten of a zaak are searched by case and mapped one by one; a failed search answers 500.
	 *
	 * @return void
	 */
	public function testTheBesluitenOfAZaakAreSearchedAndMapped(): void {
		$store = new ZrcCaseListsStore();
		$store->hits = [['id' => 'b1'], new \ArrayObject(['id' => 'b2'])];

		$response = $this->controller(store: $store)->zaakbesluitenIndex(zaakUuid: 'zaak-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['url' => 'https://nc/besluiten/b1'], ['url' => 'https://nc/besluiten/b2']], $response->getData());
		$this->assertSame(
			[['build', ['case' => 'zaak-1'], '4', '8'], ['search', ['q' => ['case' => 'zaak-1']]]],
			$store->calls
		);

		$store->hits = new \RuntimeException('down');
		$failed = $this->controller(store: $store)->zaakbesluitenIndex(zaakUuid: 'zaak-1');
		$this->assertSame(500, $failed->getStatus());
		$this->assertSame(['detail' => 'Internal server error'], $failed->getData());
		$this->assertSame(['ZRC zaakbesluiten error: down'], $this->errors);
	}//end testTheBesluitenOfAZaakAreSearchedAndMapped()

	/**
	 * Without zaken.lezen a list object is emptied, and anything else is answered as it came.
	 *
	 * @return void
	 */
	public function testAListTheConsumerMayNotReadIsEmptiedWhenItIsAList(): void {
		$this->autorisaties = [];
		$controller = $this->controller(store: new ZrcCaseListsStore());
		$method = new \ReflectionMethod($controller, 'filterCasesByAuthorisation');

		$text = new JSONResponse('not a list');
		$this->assertSame($text, $method->invoke($controller, $text));
		$this->assertSame('not a list', $text->getData());

		$shapeless = new JSONResponse(['next' => 'x']);
		$method->invoke($controller, $shapeless);
		$this->assertSame(['next' => 'x', 'count' => 0, 'results' => []], $shapeless->getData());
	}//end testAListTheConsumerMayNotReadIsEmptiedWhenItIsAList()
}//end class
