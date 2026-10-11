<?php

/**
 * The statusRole backfill: which cases it writes, and what one bad row costs.
 *
 * The store here is a fake that keeps what it is asked to patch. It does NOT
 * compute anything, which OpenRegister does on a real save. So these tests
 * say what the step ASKS for and how it counts; that OpenRegister accepts the
 * write is checked on a live instance, and the PR says so.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md#REQ-CM-77
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillCaseStatusRole;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the statusRole backfill.
 */
final class BackfillCaseStatusRoleTest extends TestCase {
	private const INTAKE = 'aaaaaaaa-0000-4000-a000-000000000001';

	private const REVIEW = 'aaaaaaaa-0000-4000-a000-000000000002';

	private const NO_ROLE = 'aaaaaaaa-0000-4000-a000-000000000003';

	/**
	 * A store that answers the step's calls and keeps what it patches.
	 *
	 * @param array<string, array<string, mixed>> $cases Cases by id.
	 *
	 * @return object The fake.
	 */
	private function store(array $cases): object {
		$statusTypes = [
			self::INTAKE => ['id' => self::INTAKE, 'name' => 'Ontvangen', 'role' => 'intake'],
			self::REVIEW => ['id' => self::REVIEW, 'name' => 'Besluit', 'role' => 'review'],
			self::NO_ROLE => ['id' => self::NO_ROLE, 'name' => 'Parafering'],
		];

		return new class(['5' => $cases, '9' => $statusTypes]) {
			/**
			 * @var array<int, array{schema: string, id: string, data: array<string, mixed>}>
			 */
			public array $patches = [];

			/**
			 * @var array<int, string>
			 */
			public array $failOn = [];

			/**
			 * @var bool Whether the store drops the field instead of storing it.
			 */
			public bool $dropsField = false;

			/**
			 * @var int How often the operation was run in system context.
			 */
			public int $systemRuns = 0;

			/**
			 * @param array<string, array<string, array<string, mixed>>> $rows Rows by schema, then id.
			 */
			public function __construct(public array $rows) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->systemRuns++;

				return $operation();
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$all = array_values($this->rows[(string)$query['@self']['schema']] ?? []);

				return array_slice($all, (int)($query['_offset'] ?? 0), (int)($query['_limit'] ?? 100));
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data The changes.
			 * @param int|string           $register The register.
			 * @param int|string           $schema The schema.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws RuntimeException When told to fail on this row.
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				if (in_array($objectId, $this->failOn, true) === true) {
					throw new RuntimeException('store refused');
				}

				$this->patches[] = ['schema' => (string)$schema, 'id' => $objectId, 'data' => $data];
				if ($this->dropsField === false) {
					$this->rows[(string)$schema][$objectId] = array_merge($this->rows[(string)$schema][$objectId], $data);
				}

				return $this->rows[(string)$schema][$objectId];
			}
		};
	}//end store()

	/**
	 * Run the step over a store and return what it reported.
	 *
	 * @param object $store The fake store.
	 *
	 * @return array<int, string> The lines written to the upgrade output.
	 */
	private function runOver(object $store): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => '23',
				'case_schema' => '5',
				'status_type_schema' => '9',
			][$key] ?? $default
		);

		$lines = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			}
		);

		(new BackfillCaseStatusRole($settings, $this->createMock(LoggerInterface::class)))->run($output);

		return $lines;
	}//end runOver()

	/**
	 * Five cases, one of each kind.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			'c-empty' => ['id' => 'c-empty', 'title' => 'No role yet', 'status' => self::INTAKE],
			'c-stale' => ['id' => 'c-stale', 'title' => 'Moved on', 'status' => self::REVIEW, 'statusRole' => 'intake'],
			'c-right' => ['id' => 'c-right', 'title' => 'Already right', 'status' => self::REVIEW, 'statusRole' => 'review'],
			'c-norole' => ['id' => 'c-norole', 'title' => 'Unannotated type', 'status' => self::NO_ROLE],
			'c-nostatus' => ['id' => 'c-nostatus', 'title' => 'No status at all'],
		];
	}//end cases()

	/**
	 * It writes the two cases that need it, one field each, in system context.
	 *
	 * @return void
	 */
	public function testItWritesOnlyTheCasesWhoseRoleIsMissingOrStale(): void {
		$store = $this->store(cases: $this->cases());

		$lines = $this->runOver(store: $store);

		$this->assertSame(
			[
				['schema' => '5', 'id' => 'c-empty', 'data' => ['statusRole' => 'intake']],
				['schema' => '5', 'id' => 'c-stale', 'data' => ['statusRole' => 'review']],
			],
			$store->patches,
			'Only statusRole is written, and only where it is missing or stale.',
		);
		$this->assertSame(1, $store->systemRuns, 'The backfill must run in system context.');
		$this->assertSame(
			['statusRole backfill: 2 filled, 0 written but not confirmed, 0 failed, 3 already right or without a role.'],
			$lines,
		);
	}//end testItWritesOnlyTheCasesWhoseRoleIsMissingOrStale()

	/**
	 * A second run finds nothing to do.
	 *
	 * @return void
	 */
	public function testASecondRunWritesNothing(): void {
		$store = $this->store(cases: $this->cases());
		$this->runOver(store: $store);
		$store->patches = [];

		$lines = $this->runOver(store: $store);

		$this->assertSame([], $store->patches);
		$this->assertSame(
			['statusRole backfill: 0 filled, 0 written but not confirmed, 0 failed, 5 already right or without a role.'],
			$lines,
		);
	}//end testASecondRunWritesNothing()

	/**
	 * One case that refuses is counted, and the others are still written.
	 *
	 * @return void
	 */
	public function testOneBadRowDoesNotStopTheRest(): void {
		$store = $this->store(cases: $this->cases());
		$store->failOn = ['c-empty'];

		$lines = $this->runOver(store: $store);

		$this->assertSame(['c-stale'], array_column($store->patches, 'id'));
		$this->assertSame(
			['statusRole backfill: 1 filled, 0 written but not confirmed, 1 failed, 3 already right or without a role.'],
			$lines,
		);
	}//end testOneBadRowDoesNotStopTheRest()

	/**
	 * A store that accepts the write and drops the field is not reported as filled.
	 *
	 * @return void
	 */
	public function testAWriteTheStoreDroppedIsNotCountedAsFilled(): void {
		$store = $this->store(cases: $this->cases());
		$store->dropsField = true;

		$lines = $this->runOver(store: $store);

		$this->assertSame(
			['statusRole backfill: 0 filled, 2 written but not confirmed, 0 failed, 3 already right or without a role.'],
			$lines,
		);
	}//end testAWriteTheStoreDroppedIsNotCountedAsFilled()

	/**
	 * More cases than one page are all read before any is written.
	 *
	 * @return void
	 */
	public function testItReadsEveryPage(): void {
		$cases = [];
		for ($i = 0; $i < 450; $i++) {
			$id = sprintf('case-%03d', $i);
			$cases[$id] = ['id' => $id, 'title' => $id, 'status' => self::INTAKE];
		}

		$store = $this->store(cases: $cases);

		$lines = $this->runOver(store: $store);

		$this->assertCount(450, $store->patches);
		$this->assertSame(
			['statusRole backfill: 450 filled, 0 written but not confirmed, 0 failed, 0 already right or without a role.'],
			$lines,
		);
	}//end testItReadsEveryPage()

	/**
	 * Without OpenRegister the step says so and does nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('OpenRegister unavailable, skipping the statusRole backfill.');

		(new BackfillCaseStatusRole($settings, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testWithoutOpenRegisterItSkips()
}//end class
