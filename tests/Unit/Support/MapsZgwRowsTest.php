<?php

/**
 * MapsZgwRows — the shared ZGW list, mapping and failure steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Support\MapsZgwRows;
use OCA\Dossiq\Support\NormalisesObjectRows;
use OCP\AppFramework\Http;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The row trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Support\MapsZgwRows
 */
final class MapsZgwRowsTest extends TestCase {

	/**
	 * The ZGW service the host delegates to.
	 *
	 * @var ZgwServiceInterface&MockObject
	 */
	private ZgwServiceInterface&MockObject $zgwService;

	/**
	 * Set up the mocked service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->zgwService = $this->createMock(ZgwServiceInterface::class);
	}//end setUp()

	/**
	 * A host composing the trait, exposing its private methods.
	 *
	 * @return object The host.
	 */
	private function host(): object {
		return new class($this->zgwService) {
			use MapsZgwRows;
			use NormalisesObjectRows;

			/**
			 * Constructor.
			 *
			 * @param ZgwServiceInterface $zgwService The ZGW service.
			 */
			public function __construct(
				private readonly ZgwServiceInterface $zgwService,
			) {
			}//end __construct()

			/**
			 * Call a private trait method.
			 *
			 * @param string       $method The method name.
			 * @param array<mixed> $args   The named arguments.
			 *
			 * @return mixed
			 */
			public function call(string $method, array $args): mixed {
				return $this->$method(...$args);
			}//end call()
		};
	}//end host()

	/**
	 * The query parameters are translated, capped at 100 and searched in the mapped register.
	 *
	 * @return void
	 */
	public function testFilteredRowsSearchTheMappedRegister(): void {
		$this->zgwService->method('translateQueryParams')->willReturn(['case' => 'c1']);
		$objectService = new class {
			/**
			 * The parameters the last query was built from.
			 *
			 * @var array<string, mixed>
			 */
			public array $built = [];

			/**
			 * Record the query parameters.
			 *
			 * @param array<string, mixed> $requestParams The parameters.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				$this->built = $requestParams + ['@register' => $register, '@schema' => $schema];
				return $this->built;
			}//end buildSearchQuery()

			/**
			 * One page of results.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query): array {
				return ['results' => [['id' => 'r1']]];
			}//end searchObjectsPaginated()
		};

		$rows = $this->host()->call('filteredRows', [
			'objectService' => $objectService,
			'mappingConfig' => ['sourceRegister' => 'reg', 'sourceSchema' => 'sch'],
			'params' => ['zaak' => 'c1'],
		]);

		self::assertSame([['id' => 'r1']], $rows);
		self::assertSame(['case' => 'c1', '_limit' => 100, '@register' => 'reg', '@schema' => 'sch'], $objectService->built);
	}//end testFilteredRowsSearchTheMappedRegister()

	/**
	 * Every row goes through one outbound mapping.
	 *
	 * @return void
	 */
	public function testOutboundRowsMapEachRow(): void {
		$this->zgwService->expects($this->once())->method('createOutboundMapping')->willReturn(new \stdClass());
		$this->zgwService->method('applyOutboundMapping')->willReturnCallback(
			static fn (array $objectData, object $mapping, array $mappingConfig, string $baseUrl): array => ['url' => $baseUrl . '/' . $objectData['id']]
		);

		$rows = $this->host()->call('outboundRows', ['rows' => [['id' => 'a'], ['id' => 'b']], 'mappingConfig' => [], 'baseUrl' => 'https://x']);

		self::assertSame([['url' => 'https://x/a'], ['url' => 'https://x/b']], $rows);
	}//end testOutboundRowsMapEachRow()

	/**
	 * A server error hides the message, a failure shows it; both are logged.
	 *
	 * @return void
	 */
	public function testFailuresAreLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('error')->with('X: boom');
		$this->zgwService->method('getLogger')->willReturn($logger);
		$host = $this->host();
		$e = new \RuntimeException('boom');

		$server = $host->call('loggedServerError', ['logPrefix' => 'X: ', 'e' => $e]);
		$failure = $host->call('loggedFailure', ['logPrefix' => 'X: ', 'e' => $e]);

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $server->getStatus());
		self::assertSame(['detail' => 'Internal server error'], $server->getData());
		self::assertSame(Http::STATUS_BAD_REQUEST, $failure->getStatus());
		self::assertSame(['detail' => 'boom'], $failure->getData());
	}//end testFailuresAreLogged()
}//end class
