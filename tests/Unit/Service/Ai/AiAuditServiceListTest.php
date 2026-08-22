<?php

/**
 * AiAuditService::listAuditEntries() Unit Tests
 *
 * Covers filter/paging pass-through, limit clamping, and graceful
 * degradation (empty result + warning, no throw) when AI audit storage is
 * unconfigured or the OpenRegister lookup fails.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\AiAuditLog;
use OCA\Dossiq\Service\Ai\AiAuditService;
use OCA\Dossiq\Service\Ai\AiModelIdentity;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub matching the named-arg signatures used by the
 * SearchesObjects trait's slug path (register/schema config values in
 * procest are slugs, e.g. "procest" / "aiAuditEntry").
 */
interface AiAuditObjectServiceStub {

	/**
	 * Search objects by register/schema slug.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array $filters The query filters.
	 *
	 * @return array
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters): array;
}//end interface

/**
 * Unit tests for AiAuditService::listAuditEntries().
 *
 * @covers \OCA\Dossiq\Service\Ai\AiAuditService
 *
 * @uses \OCA\Dossiq\Service\Ai\AiAuditLog
 * @uses \OCA\Dossiq\Service\Ai\AiModelIdentity
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class AiAuditServiceListTest extends TestCase {

	/**
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	private AiAuditService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new AiAuditService(
			audit: new AiAuditLog($this->appConfig, $this->container, $this->logger),
			modelIdentity: new AiModelIdentity($this->appConfig),
		);
	}//end setUp()

	/**
	 * Configure appConfig to resolve register + ai_audit_entry_schema.
	 *
	 * @return void
	 */
	private function configureRegisterAndSchema(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default) {
				if ($key === 'register') {
					return 'procest';
				}

				if ($key === 'ai_audit_entry_schema') {
					return 'aiAuditEntry';
				}

				return $default;
			});
	}//end configureRegisterAndSchema()

	/**
	 * Filters (caseId, type) and paging (_limit, _offset, _order) reach the
	 * OpenRegister search call, and the raw rows come back unmodified.
	 *
	 * @return void
	 */
	public function testListAuditEntriesAppliesFiltersAndPaging(): void {
		$this->configureRegisterAndSchema();

		$rows = [
			['id' => 'a1', 'type' => 'classification', 'caseId' => 'case-a'],
		];

		$objectService = $this->createMock(AiAuditObjectServiceStub::class);
		$objectService->expects($this->once())
			->method('searchObjectsBySlug')
			->with(
				'procest',
				'aiAuditEntry',
				$this->callback(function (array $filters) {
					return ($filters['caseId'] ?? null) === 'case-a'
						&& ($filters['type'] ?? null) === 'classification'
						&& ($filters['_limit'] ?? null) === 25
						&& ($filters['_offset'] ?? null) === 10
						&& ($filters['_order'] ?? null) === ['timestamp' => 'DESC'];
				})
			)
			->willReturn($rows);

		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->listAuditEntries(
			filters: ['caseId' => 'case-a', 'type' => 'classification'],
			limit: 25,
			offset: 10,
		);

		$this->assertSame($rows, $result['entries']);
		$this->assertSame(25, $result['limit']);
		$this->assertSame(10, $result['offset']);
	}//end testListAuditEntriesAppliesFiltersAndPaging()

	/**
	 * A limit above 200 is clamped down to 200.
	 *
	 * @return void
	 */
	public function testListAuditEntriesClampsOversizedLimit(): void {
		$this->configureRegisterAndSchema();

		$objectService = $this->createMock(AiAuditObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')
			->with(
				'procest',
				'aiAuditEntry',
				$this->callback(fn (array $filters) => ($filters['_limit'] ?? null) === 200)
			)
			->willReturn([]);

		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->listAuditEntries(filters: [], limit: 5000, offset: 0);

		$this->assertSame(200, $result['limit']);
	}//end testListAuditEntriesClampsOversizedLimit()

	/**
	 * A non-positive limit falls back to the default of 50.
	 *
	 * @return void
	 */
	public function testListAuditEntriesDefaultsNonPositiveLimit(): void {
		$this->configureRegisterAndSchema();

		$objectService = $this->createMock(AiAuditObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')
			->with(
				'procest',
				'aiAuditEntry',
				$this->callback(fn (array $filters) => ($filters['_limit'] ?? null) === 50)
			)
			->willReturn([]);

		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->listAuditEntries(filters: [], limit: 0, offset: 0);

		$this->assertSame(50, $result['limit']);
	}//end testListAuditEntriesDefaultsNonPositiveLimit()

	/**
	 * A negative offset is clamped to zero.
	 *
	 * @return void
	 */
	public function testListAuditEntriesClampsNegativeOffset(): void {
		$this->configureRegisterAndSchema();

		$objectService = $this->createMock(AiAuditObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturn([]);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->service->listAuditEntries(filters: [], limit: 50, offset: -5);

		$this->assertSame(0, $result['offset']);
	}//end testListAuditEntriesClampsNegativeOffset()

	/**
	 * Unconfigured register/schema degrades to an empty result with a
	 * logged warning — no exception, no OpenRegister call.
	 *
	 * @return void
	 */
	public function testListAuditEntriesReturnsEmptyWhenUnconfigured(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('not configured'));

		$this->container->expects($this->never())->method('get');

		$result = $this->service->listAuditEntries(filters: [], limit: 50, offset: 0);

		$this->assertSame([], $result['entries']);
		$this->assertNull($result['total']);
	}//end testListAuditEntriesReturnsEmptyWhenUnconfigured()

	/**
	 * An OpenRegister lookup failure degrades to an empty result with a
	 * logged error — no throw.
	 *
	 * @return void
	 */
	public function testListAuditEntriesReturnsEmptyOnObjectServiceFailure(): void {
		$this->configureRegisterAndSchema();

		$this->container->method('get')->willThrowException(new \RuntimeException('OR unavailable'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->service->listAuditEntries(filters: [], limit: 50, offset: 0);

		$this->assertSame([], $result['entries']);
	}//end testListAuditEntriesReturnsEmptyOnObjectServiceFailure()
}//end class
