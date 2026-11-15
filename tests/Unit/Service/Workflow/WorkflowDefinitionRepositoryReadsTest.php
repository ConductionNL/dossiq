<?php

/**
 * Unit tests for the reads of WorkflowDefinitionRepository.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Definitions, case types, cases and status types are read from their own
 * schemas; an unconfigured schema or a failing store answers the safe value.
 *
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository
 */
class WorkflowDefinitionRepositoryReadsTest extends TestCase {

	/**
	 * The configuration: slugs, so the slug search runs.
	 *
	 * @var array<string, string>
	 */
	private const CONFIG = [
		'register' => 'dossiq',
		'workflow_template_schema' => 'workflowTemplate',
		'case_type_schema' => 'caseType',
		'case_schema' => 'case',
		'status_type_schema' => 'statusType',
	];

	/**
	 * Errors logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * Each schema key is configured only when its schema is.
	 *
	 * @return void
	 */
	public function testIsConfiguredFor(): void {
		$config = self::CONFIG;
		unset($config['case_schema']);
		$repository = $this->repository(store: $this->store([]), config: $config);

		$this->assertTrue($repository->isConfiguredFor(WorkflowDefinitionRepository::SCHEMA_DEFINITION));
		$this->assertFalse($repository->isConfiguredFor(WorkflowDefinitionRepository::SCHEMA_CASE));
		$this->assertFalse($this->repository(store: null)->isConfiguredFor(WorkflowDefinitionRepository::SCHEMA_DEFINITION));
	}//end testIsConfiguredFor()

	/**
	 * Single reads find the row in their own schema; an empty id reads nothing.
	 *
	 * @return void
	 */
	public function testSingleReadsUseTheirOwnSchema(): void {
		$store = $this->store(
			[
				'workflowTemplate' => [['id' => 'def-1', 'title' => 'Route']],
				'caseType' => [['id' => 'ct-1', 'title' => 'Omgevingsvergunning']],
				'case' => [['id' => 'case-1', 'title' => 'Zaak']],
			]
		);
		$repository = $this->repository(store: $store);

		$this->assertSame(['id' => 'def-1', 'title' => 'Route'], $repository->findById('def-1'));
		$this->assertSame(['id' => 'ct-1', 'title' => 'Omgevingsvergunning'], $repository->findCaseType('ct-1'));
		$this->assertSame(['id' => 'case-1', 'title' => 'Zaak'], $repository->findCase('case-1'));
		$this->assertNull($repository->findById(''));
		$this->assertSame([['def-1', 'workflowTemplate'], ['ct-1', 'caseType'], ['case-1', 'case']], $store->finds);
	}//end testSingleReadsUseTheirOwnSchema()

	/**
	 * A failing single read is logged and answers null.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndNull(): void {
		$repository = $this->repository(store: $this->store([]));

		$this->assertNull($repository->findCaseType('ct-gone'));
		$this->assertNull($repository->findCase('case-gone'));
		$this->assertSame(
			['Dossiq: failed to load case type for its default route', 'Dossiq: failed to load case for definition lookup'],
			$this->errors
		);
	}//end testAFailingReadIsLoggedAndNull()

	/**
	 * A case type's versions are listed newest first, and the next version is
	 * one past the highest.
	 *
	 * @return void
	 */
	public function testVersionsAreListedNewestFirst(): void {
		$store = $this->store(
			[
				'workflowTemplate' => [
					['id' => 'v1', 'caseType' => 'ct-1', 'version' => 1],
					['id' => 'v3', 'caseType' => 'ct-1', 'version' => '3'],
					['id' => 'v2', 'caseType' => 'ct-1', 'version' => 2],
					['id' => 'other', 'caseType' => 'ct-2', 'version' => 9],
				],
			]
		);
		$repository = $this->repository(store: $store);

		$this->assertSame(['v3', 'v2', 'v1'], array_column($repository->listVersionsForCaseType('ct-1'), 'id'));
		$this->assertSame(4, $repository->nextVersionFor('ct-1'));
		$this->assertSame(1, $repository->nextVersionFor('ct-none'));
	}//end testVersionsAreListedNewestFirst()

	/**
	 * A case type's status type ids are listed; rows without an id are skipped.
	 *
	 * @return void
	 */
	public function testStatusTypeIdsAreListed(): void {
		$store = $this->store(
			[
				'statusType' => [
					['id' => 'st-1', 'caseType' => 'ct-1'],
					['caseType' => 'ct-1', 'title' => 'no id'],
					['id' => 'st-2', 'caseType' => 'ct-1'],
					['id' => 'st-9', 'caseType' => 'ct-2'],
				],
			]
		);

		$this->assertSame(['st-1', 'st-2'], $this->repository(store: $store)->listStatusTypeIds('ct-1'));
	}//end testStatusTypeIdsAreListed()

	/**
	 * Whether a case type has cases; unknown, unconfigured or unreadable
	 * counts as having cases.
	 *
	 * @return void
	 */
	public function testHasCasesFor(): void {
		$store = $this->store(['case' => [['id' => 'c-1', 'caseType' => 'ct-1']]]);
		$repository = $this->repository(store: $store);

		$this->assertTrue($repository->hasCasesFor('ct-1'));
		$this->assertFalse($repository->hasCasesFor('ct-2'));
		$this->assertTrue($this->repository(store: null)->hasCasesFor('ct-2'));
	}//end testHasCasesFor()

	/**
	 * A failing search answers the safe value and is logged.
	 *
	 * @return void
	 */
	public function testAFailingSearchAnswersTheSafeValue(): void {
		$store = $this->store([]);
		$store->searchFails = true;
		$repository = $this->repository(store: $store);

		$this->assertSame([], $repository->listVersionsForCaseType('ct-1'));
		$this->assertSame([], $repository->listStatusTypeIds('ct-1'));
		$this->assertTrue($repository->hasCasesFor('ct-1'));
		$this->assertSame(
			[
				'Dossiq: failed to list workflow definitions for caseType',
				'Dossiq: failed to list statusTypes for caseType',
				'Dossiq: failed to count open cases for caseType',
			],
			$this->errors
		);
	}//end testAFailingSearchAnswersTheSafeValue()

	/**
	 * Without the schemas, lists are empty and nothing is searched.
	 *
	 * @return void
	 */
	public function testUnconfiguredListsAreEmpty(): void {
		$store = $this->store(['workflowTemplate' => [['id' => 'v1', 'caseType' => 'ct-1']]]);
		$repository = $this->repository(store: $store, config: ['register' => 'dossiq']);

		$this->assertSame([], $repository->listVersionsForCaseType('ct-1'));
		$this->assertSame([], $repository->listStatusTypeIds('ct-1'));
		$this->assertNull($repository->findById('v1'));
		$this->assertSame(0, $store->searches);
	}//end testUnconfiguredListsAreEmpty()

	/**
	 * An in-memory store answering find() and the slug search by schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema slug.
	 *
	 * @return object
	 */
	private function store(array $rows): object {
		return new class ($rows) {
			/**
			 * The finds, as [id, schema].
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public array $finds = [];

			/**
			 * The number of searches.
			 *
			 * @var integer
			 */
			public int $searches = 0;

			/**
			 * Whether searches fail.
			 *
			 * @var boolean
			 */
			public bool $searchFails = false;

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * Find one row.
			 *
			 * @param string      $id       The id.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): array {
				$this->finds[] = [$id, (string)$schema];
				foreach (($this->rows[$schema] ?? []) as $row) {
					if (($row['id'] ?? null) === $id) {
						return $row;
					}
				}

				throw new RuntimeException('Object not found: ' . $id);
			}

			/**
			 * Search by slug, matching the plain filters.
			 *
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 * @param array  $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches++;
				if ($this->searchFails === true) {
					throw new RuntimeException('search down');
				}

				$limit = (int)($filters['_limit'] ?? 0);
				unset($filters['_limit']);
				$matched = array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters
					)
				);

				return $limit > 0 ? array_slice($matched, 0, $limit) : $matched;
			}
		};
	}//end store()

	/**
	 * The repository over a store and configuration.
	 *
	 * @param object|null           $store  The store, or null for no OpenRegister.
	 * @param array<string, string> $config The configuration.
	 *
	 * @return WorkflowDefinitionRepository
	 */
	private function repository(?object $store, array $config = self::CONFIG): WorkflowDefinitionRepository {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new WorkflowDefinitionRepository($settings, $logger);
	}//end repository()
}//end class
