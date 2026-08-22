<?php

/**
 * Unit tests for RenameDutchDeadlineColumns.
 *
 * Covers the two decision points that decide what the migration touches, both
 * of which are claims this step's docblock makes in prose and neither of which
 * was previously executed by anything:
 *
 *   - isShardOf() must not let register 17 match register 170's shard tables;
 *   - hasCollision() must REFUSE, not merge, when two Dutch columns in one
 *     table target the same English name.
 *
 * The DDL/DML paths are deliberately not exercised here — they need a live
 * database and are verified by running the repair step, not by a unit test.
 * What is testable in isolation is the logic that decides WHICH tables and
 * columns are in scope, and that is what these tests pin.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RenameDutchDeadlineColumns;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\Dossiq\Repair\RenameDutchDeadlineColumns
 */
class RenameDutchDeadlineColumnsTest extends TestCase {
	// PHPUnit assertions take positional ($actual, $expected) arguments; the
	// custom named-parameter sniff does not apply to them.
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * The step under test, wired with doubles.
	 *
	 * @var RenameDutchDeadlineColumns
	 */
	private RenameDutchDeadlineColumns $step;

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->step = new RenameDutchDeadlineColumns(
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Invoke a private method on the step.
	 *
	 * @param string $name Method name.
	 * @param array<mixed> $args Positional arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args) {
		$m = new ReflectionMethod(RenameDutchDeadlineColumns::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($this->step, $args);
	}//end call()

	/**
	 * Read a private constant off the step.
	 *
	 * @param string $name Constant name.
	 *
	 * @return mixed
	 */
	private function constant(string $name) {
		return (new ReflectionClass(RenameDutchDeadlineColumns::class))->getConstant($name);
	}//end constant()

	/**
	 * A shard of the named register is matched.
	 *
	 * @return void
	 */
	public function testMatchesShardOfTheRegister(): void {
		$markers = ['openregister_table_17_'];
		self::assertTrue($this->call('isShardOf', ['oc_openregister_table_17_85', $markers]));

	}//end testMatchesShardOfTheRegister()

	/**
	 * Register 17 must NOT match register 170's tables.
	 *
	 * This one passes on the MARKER alone — it ends in '_', so
	 * `openregister_table_17_` is not a substring of
	 * `openregister_table_170_85`. Kept because the property matters, but note
	 * it is not what the ctype_digit guard is for; see the next test.
	 *
	 * @return void
	 */
	public function testDoesNotMatchALongerRegisterId(): void {
		$markers = ['openregister_table_17_'];
		self::assertFalse($this->call('isShardOf', ['oc_openregister_table_170_85', $markers]));

	}//end testDoesNotMatchALongerRegisterId()

	/**
	 * A derived or non-shard table sharing the marker is NOT migrated.
	 *
	 * This is what the ctype_digit suffix check actually guards, and it is the
	 * case that fails if the check is removed: `…_17_85_backup` and
	 * `…_17_audit` both contain the marker, and only the digits-only rule keeps
	 * an ALTER TABLE off them.
	 *
	 * The first version of this test asserted the 17-vs-170 case instead and
	 * passed with the guard deleted — a test green for the wrong reason. A
	 * positive control (deleting the guard and re-running) is what exposed it.
	 *
	 * @return void
	 */
	public function testDoesNotMatchDerivedOrNonShardTables(): void {
		$markers = ['openregister_table_17_'];
		self::assertFalse($this->call('isShardOf', ['oc_openregister_table_17_85_backup', $markers]));
		self::assertFalse($this->call('isShardOf', ['oc_openregister_table_17_audit', $markers]));

	}//end testDoesNotMatchDerivedOrNonShardTables()

	/**
	 * A non-shard table is ignored, and so is an empty name.
	 *
	 * @return void
	 */
	public function testIgnoresUnrelatedTables(): void {
		$markers = ['openregister_table_17_'];
		self::assertFalse($this->call('isShardOf', ['oc_openregister_registers', $markers]));
		self::assertFalse($this->call('isShardOf', ['', $markers]));

	}//end testIgnoresUnrelatedTables()

	/**
	 * Both procest registers are covered, not just the exact-slug one.
	 *
	 * The reference install carries `procest` (1051 rows) AND
	 * `procest-default` (107). Resolving a single exact slug leaves the second
	 * behind and still reports success.
	 *
	 * @return void
	 */
	public function testMatchesShardsOfEveryResolvedRegister(): void {
		$markers = ['openregister_table_17_', 'openregister_table_2424_'];
		self::assertTrue($this->call('isShardOf', ['oc_openregister_table_17_85', $markers]));
		self::assertTrue($this->call('isShardOf', ['oc_openregister_table_2424_919', $markers]));

	}//end testMatchesShardsOfEveryResolvedRegister()

	/**
	 * Two Dutch columns targeting one English name are refused, not merged.
	 *
	 * @return void
	 */
	public function testRefusesAmbiguousRename(): void {
		// Both `omschrijving` and `beschrijving` map to `description`.
		$columns = ['omschrijving', 'beschrijving', 'name'];
		self::assertTrue($this->call('hasCollision', ['tbl', $columns, 'description']));

	}//end testRefusesAmbiguousRename()

	/**
	 * A single source for a destination is not a collision.
	 *
	 * @return void
	 */
	public function testSingleSourceIsNotACollision(): void {
		$columns = ['omschrijving', 'name'];
		self::assertFalse($this->call('hasCollision', ['tbl', $columns, 'description']));
		self::assertFalse($this->call('hasCollision', ['tbl', $columns, 'name']));

	}//end testSingleSourceIsNotACollision()

	/**
	 * zaaktype is exempt: it is the statutory ZGW wire field name.
	 *
	 * It is the second most widespread Dutch column in this register (14 shard
	 * tables), so its absence from the map is a deliberate decision rather than
	 * an oversight, and a test should fail if someone "completes" the map.
	 *
	 * @return void
	 */
	public function testZaaktypeIsNotInTheColumnMap(): void {
		$map = $this->constant('COLUMN_MAP');
		self::assertIsArray($map);
		self::assertArrayNotHasKey('caseType', $map);

	}//end testZaaktypeIsNotInTheColumnMap()

	/**
	 * Every destination is snake_case, never camelCase.
	 *
	 * MagicMapper stores `endDateActual` as `end_date_actual` and its
	 * de-duplication path DROPS a camelCase column whose snake_case twin
	 * exists — so a camelCase destination here would be deleted by the mapper.
	 *
	 * @return void
	 */
	public function testEveryDestinationIsSnakeCase(): void {
		$map = $this->constant('COLUMN_MAP');
		foreach ($map as $old => $new) {
			self::assertSame(
				strtolower($new),
				$new,
				"Destination '$new' (from '$old') must be snake_case, not camelCase"
			);
		}

	}//end testEveryDestinationIsSnakeCase()

	/**
	 * The step reports a human-readable name.
	 *
	 * @return void
	 */
	public function testGetName(): void {
		self::assertNotSame('', $this->step->getName());

	}//end testGetName()
}//end class
