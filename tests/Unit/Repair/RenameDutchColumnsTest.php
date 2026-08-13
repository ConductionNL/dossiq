<?php

/**
 * Tests for the Dutch-to-English column migration.
 *
 * These assert the properties I had been checking by hand on every batch, which
 * is precisely why they belong in the suite: a hand-check does not run again
 * when someone extends COLUMN_MAP.
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Repair;

use OCA\Procest\Repair\RenameDutchColumns;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guards the shape of the vocabulary column migration.
 */
final class RenameDutchColumnsTest extends TestCase {
	/**
	 * The step under test.
	 *
	 * @var RenameDutchColumns
	 */
	private RenameDutchColumns $step;

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->step = new RenameDutchColumns(
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Read the column map off the step.
	 *
	 * @return array<string, string>
	 */
	private function map(): array {
		return (array)(new ReflectionClass(RenameDutchColumns::class))->getConstant('COLUMN_MAP');
	}//end map()

	/**
	 * Invoke a private method on the step.
	 *
	 * @param string       $name Method name.
	 * @param array<mixed> $args Positional arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args) {
		$m = new ReflectionMethod(RenameDutchColumns::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($this->step, $args);
	}//end call()

	/**
	 * The map is not empty and every entry is a non-empty snake_case pair.
	 *
	 * Snake_case matters: OpenRegister stores `requestedAmount` as the column
	 * `requested_amount`, and a camelCase entry would simply never match a real
	 * column — a migration that silently does nothing.
	 *
	 * @return void
	 */
	public function testEveryEntryIsSnakeCase(): void {
		$map = $this->map();
		self::assertNotSame([], $map, 'the column map must not be empty');

		foreach ($map as $old => $new) {
			self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string)$old, "source `$old` is not snake_case");
			self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string)$new, "target `$new` is not snake_case");
			self::assertNotSame($old, $new, "`$old` maps to itself");
		}
	}//end testEveryEntryIsSnakeCase()

	/**
	 * No target is also a source.
	 *
	 * A chain (`a => b`, `b => c`) would move data twice depending on iteration
	 * order, which is the kind of bug that only shows up on real data.
	 *
	 * @return void
	 */
	public function testNoTargetIsAlsoASource(): void {
		$map = $this->map();
		foreach ($map as $old => $new) {
			self::assertArrayNotHasKey($new, $map, "`$new` is both a target and a source, forming a rename chain");
		}
	}//end testNoTargetIsAlsoASource()

	/**
	 * An ambiguous rename is refused rather than merged.
	 *
	 * When two Dutch columns both map to one English name AND both exist in the
	 * same table, migrating either one loses data. The step must decline.
	 *
	 * @return void
	 */
	public function testRefusesAmbiguousRename(): void {
		$map = $this->map();
		$targets = [];
		foreach ($map as $old => $new) {
			$targets[$new][] = $old;
		}

		$ambiguous = array_filter($targets, static fn(array $sources): bool => count($sources) > 1);
		if ($ambiguous === []) {
			self::markTestSkipped('no target in this map has more than one source');
		}

		$target = (string)array_key_first($ambiguous);
		$columns = $ambiguous[$target];

		self::assertTrue(
			$this->call('hasCollision', [$columns, $target]),
			'two sources for one destination in one table must be refused, not merged'
		);
	}//end testRefusesAmbiguousRename()

	/**
	 * A single source for a destination is not treated as a collision.
	 *
	 * The negative control for the test above: without it, a guard that always
	 * returned true would pass and silently migrate nothing at all.
	 *
	 * @return void
	 */
	public function testSingleSourceIsNotACollision(): void {
		$map = $this->map();
		$old = (string)array_key_first($map);

		self::assertFalse(
			$this->call('hasCollision', [[$old], $map[$old]]),
			'one source for a destination must migrate normally'
		);
	}//end testSingleSourceIsNotACollision()

	/**
	 * The step names itself for the repair log.
	 *
	 * @return void
	 */
	public function testHasAHumanReadableName(): void {
		self::assertNotSame('', trim($this->step->getName()));
	}//end testHasAHumanReadableName()
}//end class
