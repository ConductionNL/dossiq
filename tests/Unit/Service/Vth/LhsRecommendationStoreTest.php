<?php

/**
 * Tests for the LHS recommendation store.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/enforcement-lhs/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Vth;


use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Vth\LhsRecommendationStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The slice of OpenRegister's ObjectService the store calls, for mocking.
 */
class LhsStoreObjectService {

	/**
	 * Find one object.
	 *
	 * @param string     $id       The id.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema.
	 *
	 * @return mixed
	 */
	public function find(string $id, int|string $register = '', int|string $schema = ''): mixed {
		return null;
	}//end find()

	/**
	 * Save one object.
	 *
	 * @param int|string           $register The register.
	 * @param int|string           $schema   The schema.
	 * @param array<string, mixed> $object   The object.
	 *
	 * @return mixed
	 */
	public function saveObject(int|string $register = '', int|string $schema = '', array $object = []): mixed {
		return null;
	}//end saveObject()

	/**
	 * Find objects.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return mixed
	 */
	public function findAll(array $config = []): mixed {
		return null;
	}//end findAll()
}//end class

/**
 * The store reads and writes recommendations and reads the matrix, refusing loudly.
 *
 * @covers \OCA\Dossiq\Service\Vth\LhsRecommendationStore
 *
 * @spec openspec/specs/enforcement-lhs/spec.md
 */
class LhsRecommendationStoreTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var LhsStoreObjectService|MockObject
	 */
	private LhsStoreObjectService $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The configuration the store reads.
	 *
	 * @var array<string, string>
	 */
	private array $config = [
		'register' => 'dossiq',
		'lhs_recommendation_schema' => 'lhsRecommendation',
		'lhs_matrix_schema' => 'lhsMatrix',
	];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(LhsStoreObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The store under test.
	 *
	 * @param bool $withService Whether OpenRegister is available.
	 *
	 * @return LhsRecommendationStore
	 */
	private function store(bool $withService = true): LhsRecommendationStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($withService === true ? $this->objects : null));
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);

		return new LhsRecommendationStore(settingsService: $settings, logger: $this->logger);
	}//end store()

	/**
	 * The recommendation store resolves the service, register and schema.
	 *
	 * @return void
	 */
	public function testRecommendationStoreResolvesItsThreeParts(): void {
		$this->assertSame([$this->objects, 'dossiq', 'lhsRecommendation'], $this->store()->recommendationStore());
	}//end testRecommendationStoreResolvesItsThreeParts()

	/**
	 * Without OpenRegister the store refuses.
	 *
	 * @return void
	 */
	public function testRecommendationStoreWithoutOpenRegisterThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is niet beschikbaar');

		$this->store(withService: false)->recommendationStore();
	}//end testRecommendationStoreWithoutOpenRegisterThrows()

	/**
	 * Without a recommendation schema the store refuses.
	 *
	 * @return void
	 */
	public function testRecommendationStoreWithoutASchemaThrows(): void {
		$this->config['lhs_recommendation_schema'] = '';
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('LHS-recommendation register/schema is niet geconfigureerd');

		$this->store()->recommendationStore();
	}//end testRecommendationStoreWithoutASchemaThrows()

	/**
	 * A stored recommendation is read on its schema.
	 *
	 * @return void
	 */
	public function testLoadRecommendationReadsTheRow(): void {
		$this->objects->expects($this->once())
			->method('find')
			->with('rec-1', 'dossiq', 'lhsRecommendation')
			->willReturn(['id' => 'rec-1', 'intervention' => 'B2']);

		$this->assertSame(['id' => 'rec-1', 'intervention' => 'B2'], $this->store()->loadRecommendation(recommendationId: 'rec-1'));
	}//end testLoadRecommendationReadsTheRow()

	/**
	 * A read that fails is logged and refused as not found.
	 *
	 * @return void
	 */
	public function testLoadRecommendationFailureIsLoggedAndRefused(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects($this->once())->method('error')->with('Dossiq LHS: kon aanbeveling niet lezen: db gone');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('LHS-aanbeveling niet gevonden');

		$this->store()->loadRecommendation(recommendationId: 'rec-1');
	}//end testLoadRecommendationFailureIsLoggedAndRefused()

	/**
	 * A read that answers nothing is refused as not found, without a log.
	 *
	 * @return void
	 */
	public function testLoadRecommendationOfNothingIsRefused(): void {
		$this->objects->method('find')->willReturn(null);
		$this->logger->expects($this->never())->method('error');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('LHS-aanbeveling niet gevonden');

		$this->store()->loadRecommendation(recommendationId: 'rec-1');
	}//end testLoadRecommendationOfNothingIsRefused()

	/**
	 * A create saves the row as given; an update saves it under its id.
	 *
	 * @return void
	 */
	public function testPersistRecommendationSavesWithTheIdOnUpdate(): void {
		$saved = [];
		$this->objects->expects($this->exactly(2))
			->method('saveObject')
			->willReturnCallback(
				function (int|string $register, int|string $schema, array $object) use (&$saved): array {
					$saved[] = [$register, $schema, $object];

					return $object + ['stored' => true];
				}
			);

		$store = $this->store();
		$this->assertSame(['intervention' => 'B2', 'stored' => true], $store->persistRecommendation(row: ['intervention' => 'B2']));
		$this->assertSame(
			['intervention' => 'C1', 'id' => 'rec-1', 'stored' => true],
			$store->persistRecommendation(row: ['intervention' => 'C1'], id: 'rec-1')
		);
		$this->assertSame(
			[
				['dossiq', 'lhsRecommendation', ['intervention' => 'B2']],
				['dossiq', 'lhsRecommendation', ['intervention' => 'C1', 'id' => 'rec-1']],
			],
			$saved
		);
	}//end testPersistRecommendationSavesWithTheIdOnUpdate()

	/**
	 * A save that fails is logged and refused.
	 *
	 * @return void
	 */
	public function testPersistRecommendationFailureIsLoggedAndRefused(): void {
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('error')->with('Dossiq LHS: failed to save lhsRecommendation: locked');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Opslaan LHS-aanbeveling mislukt');

		$this->store()->persistRecommendation(row: []);
	}//end testPersistRecommendationFailureIsLoggedAndRefused()

	/**
	 * Without a version the active matrix is asked for; with one, that version.
	 *
	 * @return void
	 */
	public function testLoadMatrixAsksForTheActiveOrTheNamedVersion(): void {
		$queries = [];
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$queries): array {
				$queries[] = $config;

				return [['version' => 3]];
			}
		);

		$store = $this->store();
		$this->assertSame(['version' => 3], $store->loadMatrix(version: null));
		$this->assertSame(['version' => 3], $store->loadMatrix(version: 2));
		$this->assertSame(
			[
				['filters' => ['register' => 'dossiq', 'schema' => 'lhsMatrix', 'active' => true], 'limit' => 1],
				['filters' => ['register' => 'dossiq', 'schema' => 'lhsMatrix', 'version' => 2], 'limit' => 1],
			],
			$queries
		);
	}//end testLoadMatrixAsksForTheActiveOrTheNamedVersion()

	/**
	 * No matrix found is refused.
	 *
	 * @return void
	 */
	public function testLoadMatrixWithoutAMatrixThrows(): void {
		$this->objects->method('findAll')->willReturn(['results' => []]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Geen actieve LHS-matrix gevonden');

		$this->store()->loadMatrix(version: null);
	}//end testLoadMatrixWithoutAMatrixThrows()

	/**
	 * A matrix lookup that fails is logged and refused.
	 *
	 * @return void
	 */
	public function testLoadMatrixFailureIsLoggedAndRefused(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('timeout'));
		$this->logger->expects($this->once())->method('error')->with('Dossiq LHS: matrix lookup failed: timeout');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('LHS-matrix lookup mislukt');

		$this->store()->loadMatrix(version: null);
	}//end testLoadMatrixFailureIsLoggedAndRefused()

	/**
	 * The matrix read refuses without OpenRegister or a matrix schema.
	 *
	 * @return void
	 */
	public function testLoadMatrixWithoutConfigurationThrows(): void {
		try {
			$this->store(withService: false)->loadMatrix(version: null);
			$this->fail('A matrix was read without OpenRegister.');
		} catch (RuntimeException $e) {
			$this->assertSame('OpenRegister is niet beschikbaar', $e->getMessage());
		}

		$this->config['lhs_matrix_schema'] = '';
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('LHS-matrix register/schema is niet geconfigureerd');

		$this->store()->loadMatrix(version: null);
	}//end testLoadMatrixWithoutConfigurationThrows()

	/**
	 * The first row is read from a list or a paged result, else null.
	 *
	 * @return void
	 */
	public function testFirstRowReadsListsAndPagedResults(): void {
		$store = $this->store();

		$this->assertSame('a', $store->firstRow(results: ['a', 'b']));
		$this->assertSame('c', $store->firstRow(results: ['results' => ['c', 'd']]));
		$this->assertNull($store->firstRow(results: ['results' => []]));
		$this->assertNull($store->firstRow(results: ['results' => 'c']));
		$this->assertNull($store->firstRow(results: []));
		$this->assertNull($store->firstRow(results: 'a'));
	}//end testFirstRowReadsListsAndPagedResults()

	/**
	 * Arrays, serialisable entities, toArray entities and plain objects all become arrays.
	 *
	 * @return void
	 */
	public function testToArrayCoercesEveryShape(): void {
		$store = $this->store();

		$serialisable = new class implements \JsonSerializable {
			/**
			 * The entity as an array.
			 *
			 * @return array<string, int>
			 */
			public function jsonSerialize(): array {
				return ['a' => 1];
			}
		};
		$arrayable = new class {
			/**
			 * The entity as an array.
			 *
			 * @return array<string, int>
			 */
			public function toArray(): array {
				return ['b' => 2];
			}
		};
		$plain = new \stdClass();
		$plain->c = 3;

		$this->assertSame(['x' => 1], $store->toArray(value: ['x' => 1]));
		$this->assertSame(['a' => 1], $store->toArray(value: $serialisable));
		$this->assertSame(['b' => 2], $store->toArray(value: $arrayable));
		$this->assertSame(['c' => 3], $store->toArray(value: $plain));
		$this->assertSame([], $store->toArray(value: 'x'));
		$this->assertSame([], $store->toArray(value: null));
	}//end testToArrayCoercesEveryShape()

}//end class
