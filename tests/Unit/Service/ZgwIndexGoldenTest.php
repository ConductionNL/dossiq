<?php

/**
 * ZgwService list golden tests.
 *
 * Pins what the generic list answers: the store, mapping and disabled-mapping
 * gates, the search it builds from the query (filters, page and page size,
 * clamped), the mapping of every object (array or serialisable), the page it
 * wraps, and the logged 500 on a failure.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ZgwPaginationHelper;
use OCA\Dossiq\Service\ZgwService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for ZgwService::handleIndex().
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 */
class ZgwIndexGoldenTest extends TestCase {

	/**
	 * Every call the doubles saw, in order.
	 *
	 * @var \ArrayObject<int, array<int, mixed>>
	 */
	private \ArrayObject $calls;

	/**
	 * Set up the call log.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = new \ArrayObject();
	}//end setUp()

	/**
	 * The service over a store, a mapping and a pagination double.
	 *
	 * @param array<string, mixed>|null|false $mapping The mapping; false for no store.
	 * @param mixed                           $search  What the search answers, or a Throwable.
	 *
	 * @return ZgwService
	 */
	private function service(array|null|false $mapping, mixed $search = []): ZgwService {
		$calls = $this->calls;
		$store = new class ($calls, $search) {
			public function __construct(private \ArrayObject $calls, private mixed $search) {
			}

			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				$this->calls->append(['buildSearchQuery', $requestParams, $register, $schema]);
				return ['query' => $requestParams];
			}

			public function searchObjectsPaginated(array $query): array {
				$this->calls->append(['searchObjectsPaginated', $query]);
				if ($this->search instanceof \Throwable) {
					throw $this->search;
				}

				return $this->search;
			}
		};

		$pagination = $this->createMock(ZgwPaginationHelper::class);
		$pagination->method('wrapResults')->willReturnCallback(
			static function (array $mappedObjects, int $totalCount, int $page, int $pageSize, string $baseUrl, array $queryParams) use ($calls): array {
				$calls->append(['wrapResults', $mappedObjects, $totalCount, $page, $pageSize, $baseUrl, $queryParams]);
				return ['count' => $totalCount, 'results' => $mappedObjects];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use ($calls): void {
				$calls->append(['error', $message, get_class($context['exception'])]);
			}
		);

		$service = (new \ReflectionClass(ZgwServiceListingDouble::class))->newInstanceWithoutConstructor();
		$service->mapping = ($mapping === false ? null : $mapping);
		$service->calls = $calls;
		\Closure::bind(
			static function () use ($service, $store, $pagination, $logger, $mapping): void {
				$service->objectService = ($mapping === false ? null : $store);
				$service->paginationHelper = $pagination;
				$service->logger = $logger;
			},
			null,
			ZgwService::class
		)();

		return $service;
	}//end service()

	/**
	 * A request on zgw.test asking for these parameters.
	 *
	 * @param array<string, mixed> $params The query.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getServerHost')->willReturn('zgw.test');
		$request->method('getServerProtocol')->willReturn('https');

		return $request;
	}//end request()

	/**
	 * The gates answer before anything is read.
	 *
	 * @return void
	 */
	public function testTheGates(): void {
		$this->assertSame(503, $this->service(false)->handleIndex($this->request([]), 'zaken', 'zaken')->getStatus());

		$missing = $this->service(null)->handleIndex($this->request([]), 'zaken', 'zaken');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['detail' => 'No ZGW mapping configured for zaken/zaken'], $missing->getData());

		$disabled = $this->service(['enabled' => false])->handleIndex($this->request([]), 'zaken', 'zaken');
		$this->assertSame(404, $disabled->getStatus());
		$this->assertSame(['detail' => 'ZGW mapping for zaken/zaken is disabled'], $disabled->getData());

		$this->assertSame(['loadMappingConfig', 'loadMappingConfig'], array_column($this->calls->getArrayCopy(), 0));
	}//end testTheGates()

	/**
	 * A page is searched, mapped and wrapped, call by call.
	 *
	 * @return void
	 */
	public function testAPageIsSearchedMappedAndWrapped(): void {
		$object = new class () implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'z-2'];
			}
		};
		$params = ['status' => 'open', 'page' => '3', 'pageSize' => '500'];
		$response = $this->service(['sourceRegister' => 'zgw', 'sourceSchema' => 'zaak'], ['results' => [['id' => 'z-1'], $object], 'total' => 41])
			->handleIndex($this->request($params), 'zaken', 'zaken');

		$base = 'https://zgw.test/index.php/apps/dossiq/api/zgw/zaken/v1/zaken';
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['count' => 41, 'results' => [['url' => $base . '/z-1'], ['url' => $base . '/z-2']]], $response->getData());
		$this->assertSame(
			[
				['loadMappingConfig', 'zaken', 'zaken'],
				['translateQueryParams', $params],
				['buildSearchQuery', ['filter:status' => 'open', '_limit' => 100, '_offset' => 200], 'zgw', 'zaak'],
				['searchObjectsPaginated', ['query' => ['filter:status' => 'open', '_limit' => 100, '_offset' => 200]]],
				['createOutboundMapping'],
				['applyOutboundMapping', ['id' => 'z-1'], $base],
				['applyOutboundMapping', ['id' => 'z-2'], $base],
				['wrapResults', [['url' => $base . '/z-1'], ['url' => $base . '/z-2']], 41, 3, 100, $base, $params],
			],
			$this->calls->getArrayCopy()
		);
	}//end testAPageIsSearchedMappedAndWrapped()

	/**
	 * Without paging parameters page 1 of 20 is asked, and the total defaults to the count.
	 *
	 * @return void
	 */
	public function testTheDefaultPageAndTotal(): void {
		$this->service(['sourceRegister' => 'zgw', 'sourceSchema' => 'zaak'], ['results' => [['id' => 'z-1']]])
			->handleIndex($this->request(['page' => '-4', 'pageSize' => '0']), 'zaken', 'zaken');

		$calls = $this->calls->getArrayCopy();
		$this->assertSame(['_limit' => 1, '_offset' => 0], $calls[2][1]);
		$this->assertSame([1, 1, 1], [$calls[6][2], $calls[6][3], $calls[6][4]]);

		$this->calls->exchangeArray([]);
		$this->service(['sourceRegister' => 'zgw', 'sourceSchema' => 'zaak'], [])->handleIndex($this->request([]), 'zaken', 'zaken');
		$calls = $this->calls->getArrayCopy();
		$this->assertSame(['_limit' => 20, '_offset' => 0], $calls[2][1]);
		$this->assertSame([[], 0, 1, 20], array_slice($calls[5], 1, 4));
	}//end testTheDefaultPageAndTotal()

	/**
	 * A failing search is logged and answered with a 500.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndA500(): void {
		$response = $this->service(['sourceRegister' => 'zgw', 'sourceSchema' => 'zaak'], new RuntimeException('down'))
			->handleIndex($this->request([]), 'zaken', 'zaken');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['detail' => 'Internal server error'], $response->getData());
		$this->assertSame(['error', 'ZGW list error: down', RuntimeException::class], $this->calls->getArrayCopy()[4]);
	}//end testAFailureIsLoggedAndA500()
}//end class

/**
 * ZgwService with its mapping steps recorded instead of resolved.
 */
class ZgwServiceListingDouble extends ZgwService {
	/**
	 * The mapping configuration this double answers.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $mapping = null;

	/**
	 * The shared call log.
	 *
	 * @var \ArrayObject<int, array<int, mixed>>
	 */
	public \ArrayObject $calls;

	/**
	 * @param string $zgwApi   The API.
	 * @param string $resource The resource.
	 *
	 * @return array<string, mixed>|null
	 */
	public function loadMappingConfig(string $zgwApi, string $resource): ?array {
		$this->calls->append(['loadMappingConfig', $zgwApi, $resource]);
		return $this->mapping;
	}

	/**
	 * @param array<string, mixed> $params        The query.
	 * @param array<string, mixed> $mappingConfig The mapping.
	 *
	 * @return array<string, mixed>
	 */
	public function translateQueryParams(array $params, array $mappingConfig): array {
		$this->calls->append(['translateQueryParams', $params]);
		return isset($params['status']) === true ? ['filter:status' => $params['status']] : [];
	}

	/**
	 * @param array<string, mixed> $mappingConfig The mapping.
	 *
	 * @return object
	 */
	public function createOutboundMapping(array $mappingConfig): object {
		$this->calls->append(['createOutboundMapping']);
		return new \stdClass();
	}

	/**
	 * @param array<string, mixed> $objectData    The object.
	 * @param object               $mapping       The mapping.
	 * @param array<string, mixed> $mappingConfig The mapping configuration.
	 * @param string               $baseUrl       The collection URL.
	 *
	 * @return array<string, mixed>
	 */
	public function applyOutboundMapping(array $objectData, object $mapping, array $mappingConfig, string $baseUrl): array {
		$this->calls->append(['applyOutboundMapping', $objectData, $baseUrl]);
		return ['url' => $baseUrl . '/' . $objectData['id']];
	}
}
