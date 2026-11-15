<?php

/**
 * LhsMatrixDecisionTableMigrator failure tests.
 *
 * What the migration answers when OpenRegister is missing or cannot run as
 * the user, when a matrix has no id, when listing matrices, reading the
 * existing tables or writing one fails, and how stored rules in an odd shape
 * compare against the projection.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Vth
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

namespace OCA\Dossiq\Tests\Unit\Service\Vth;

use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Vth\LhsMatrixDecisionTableMigrator;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Every failure is a summary or a failed row, never an exception.
 *
 * @covers \OCA\Dossiq\Service\Vth\LhsMatrixDecisionTableMigrator
 * @uses   \OCA\Dossiq\Service\Dmn\StoredDecisionTables
 */
class LhsMatrixDecisionTableMigratorFailuresTest extends TestCase {

	/**
	 * Log records as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logged = [];

	/**
	 * A matrix with two cells on one actor type.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function matrix(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'm-1',
				'name' => 'LHS 2024',
				'version' => 1,
				'severityAxis' => ['gering'],
				'behaviourAxis' => ['goedwillend', 'calculerend'],
				'actorTypeAxis' => ['burger'],
				'cells' => [
					['severity' => 'gering', 'behaviour' => 'goedwillend', 'actorType' => 'burger', 'intervention' => 'warning'],
					['severity' => 'gering', 'behaviour' => 'calculerend', 'actorType' => 'burger', 'intervention' => 'fine'],
				],
			],
			$overrides
		);
	}//end matrix()

	/**
	 * An object service answering matrices and tables, failing where asked, recording writes.
	 *
	 * @param array<int, mixed>         $matrices What the matrix search answers.
	 * @param array<int, mixed>         $tables   What the decision-table search answers.
	 * @param array<string, \Throwable> $failures Failure per operation: matrices, tables, save.
	 * @param bool                      $runAs    Whether it offers runAs().
	 *
	 * @return object
	 */
	private static function store(array $matrices, array $tables = [], array $failures = [], bool $runAs = true): object {
		$store = new class($matrices, $tables, $failures) {
			/** @var array<int, array<string, mixed>> */
			public array $written = [];

			/**
			 * @param array<int, mixed>         $matrices The matrices.
			 * @param array<int, mixed>         $tables   The tables.
			 * @param array<string, \Throwable> $failures The failures.
			 */
			public function __construct(private array $matrices, private array $tables, private array $failures) {
			}

			public function runAs(IUser $user, callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$which = ($schema === StoredDecisionTables::SCHEMA ? 'tables' : 'matrices');
				if (isset($this->failures[$which]) === true) {
					throw $this->failures[$which];
				}

				return $this->{$which};
			}

			/**
			 * @param array<string, mixed> $object The object.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				if (isset($this->failures['save']) === true) {
					throw $this->failures['save'];
				}

				$this->written[] = $object;
				return $object;
			}
		};

		if ($runAs === true) {
			return $store;
		}

		return new class($store) {
			public function __construct(public object $inner) {
			}

			/**
			 * @param array<int, mixed> $args The arguments.
			 */
			public function __call(string $name, array $args): mixed {
				return $this->inner->{$name}(...$args);
			}
		};
	}//end store()

	/**
	 * The migrator over the given object service and matrix schema.
	 *
	 * @param object|null $objectService The object service, or null for none.
	 * @param string      $matrixSchema  The configured matrix schema.
	 *
	 * @return LhsMatrixDecisionTableMigrator
	 */
	private function migrator(?object $objectService, string $matrixSchema = 'lhsMatrix'): LhsMatrixDecisionTableMigrator {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'lhs_matrix_schema' => $matrixSchema][$key] ?? $default)
		);

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/** @var array<int, array{0: string, 1: string}> */
			private array $records;

			/**
			 * @param array<int, array{0: string, 1: string}> $records The record.
			 */
			public function __construct(array &$records) {
				$this->records = &$records;
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = [(string)$level, (string)$message];
			}
		};

		return new LhsMatrixDecisionTableMigrator($settings, $logger, new StoredDecisionTables($settings, $this->createMock(ContainerInterface::class)));
	}//end migrator()

	/**
	 * The user the migration runs as.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		return $this->createMock(IUser::class);
	}//end user()

	/**
	 * The table a first run projects from the matrix, as stored with an id.
	 *
	 * @return array<string, mixed>
	 */
	private function projected(): array {
		$store = self::store([self::matrix()]);
		$this->migrator($store)->migrate($this->user(), false);

		return ['id' => 'table-1'] + $store->written[0];
	}//end projected()

	/**
	 * Without OpenRegister, or without runAs(), nothing is read or written and the summary says why.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterOrRunAsNothingHappens(): void {
		$empty = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => []];

		self::assertSame(['note' => 'OpenRegister is not available.'] + $empty, $this->migrator(null)->migrate($this->user(), false));

		$store = self::store([self::matrix()], [], [], false);
		self::assertSame(
			['note' => 'OpenRegister exposes no runAs(); the migration needs an owner for the tables it writes.'] + $empty,
			$this->migrator($store)->migrate($this->user(), false)
		);
		self::assertSame([], $store->inner->written);
	}//end testWithoutOpenRegisterOrRunAsNothingHappens()

	/**
	 * No matrix schema, or a failing matrix search, migrates nothing.
	 *
	 * @return void
	 */
	public function testNoMatricesCanBeListed(): void {
		$none = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0, 'rows' => []];

		self::assertSame($none, $this->migrator(self::store([self::matrix()]), '')->migrate($this->user(), false));
		self::assertSame($none, $this->migrator(self::store([], [], ['matrices' => new RuntimeException('index down')]))->migrate($this->user(), false));
		self::assertSame([['warning', 'Dossiq: could not list LHS matrices']], $this->logged);
	}//end testNoMatricesCanBeListed()

	/**
	 * A matrix without an id is a failed row and nothing is written for it.
	 *
	 * @return void
	 */
	public function testAMatrixWithoutAnIdFails(): void {
		$matrix = self::matrix();
		unset($matrix['id']);
		$store = self::store([$matrix]);

		$summary = $this->migrator($store)->migrate($this->user(), false);

		self::assertSame(1, $summary['failed']);
		self::assertSame(
			['outcome' => 'failed', 'marker' => 'dossiq:lhsMatrix:', 'detail' => 'no matrix id, or no decision-table schema configured'],
			$summary['rows'][0]
		);
		self::assertSame([], $store->written);
	}//end testAMatrixWithoutAnIdFails()

	/**
	 * A failing write is a failed row with the reason, and is logged.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsAFailedRow(): void {
		$summary = $this->migrator(self::store([self::matrix()], [], ['save' => new RuntimeException('quota exceeded')]))->migrate($this->user(), false);

		self::assertSame(['created' => 0, 'failed' => 1], ['created' => $summary['created'], 'failed' => $summary['failed']]);
		self::assertSame(['outcome' => 'failed', 'marker' => 'dossiq:lhsMatrix:m-1', 'detail' => 'quota exceeded'], $summary['rows'][0]);
		self::assertSame([['error', 'Dossiq: could not project an LHS matrix onto a decision table']], $this->logged);
	}//end testAFailingWriteIsAFailedRow()

	/**
	 * When the existing tables cannot be read, the table is created anew, and that is logged.
	 *
	 * @return void
	 */
	public function testUnreadableExistingTablesCreateAnew(): void {
		$store = self::store([self::matrix()], [], ['tables' => new RuntimeException('no access')]);

		$summary = $this->migrator($store)->migrate($this->user(), false);

		self::assertSame(1, $summary['created']);
		self::assertArrayNotHasKey('id', $store->written[0]);
		self::assertSame([['warning', 'Dossiq: could not read existing decision tables']], $this->logged);
	}//end testUnreadableExistingTablesCreateAnew()

	/**
	 * Stored rules as JSON are compared as rules; entries that are not rules are ignored.
	 *
	 * @return void
	 */
	public function testStoredRulesAreComparedWhateverTheirShape(): void {
		$stored = $this->projected();
		$rules = $stored['rules'];

		$stored['rules'] = json_encode(array_merge($rules, ['not a rule']));
		$store = self::store([self::matrix()], [$stored]);
		self::assertSame(1, $this->migrator($store)->migrate($this->user(), false)['updated']);
		self::assertSame('table-1', $store->written[0]['id']);

		foreach (['{not json', 42] as $unreadable) {
			$stored['rules'] = $unreadable;
			$summary = $this->migrator(self::store([self::matrix()], [$stored]))->migrate($this->user(), false);
			self::assertSame(1, $summary['skipped'], 'unreadable stored rules differ from the projection');
		}
	}//end testStoredRulesAreComparedWhateverTheirShape()

	/**
	 * Axes that are neither a list nor JSON leave the matrix unprojectable.
	 *
	 * @return void
	 */
	public function testAxesInNoKnownFormSkipTheMatrix(): void {
		$summary = $this->migrator(self::store([self::matrix(['actorTypeAxis' => 7])]))->migrate($this->user(), false);

		self::assertSame(1, $summary['skipped']);
		self::assertSame('a cell names a value that is not on its axis', $summary['rows'][0]['detail']);
	}//end testAxesInNoKnownFormSkipTheMatrix()
}//end class
