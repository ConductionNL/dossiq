<?php

/**
 * Tests for the pure decisions behind the schema-slug migration.
 *
 * @category  Test
 * @package   OCA\Procest\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Repair;

use OCA\Procest\Repair\RenameDutchSchemaSlugDecisions;
use OCA\Procest\Repair\RenameDutchSchemaSlugs;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The slug migration's decisions, exercised without a database.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Procest\Repair\RenameDutchSchemaSlugDecisions
 * @covers \OCA\Procest\Repair\RenameDutchSchemaSlugs
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
final class RenameDutchSchemaSlugDecisionsTest extends TestCase {

	/**
	 * The decisions under test.
	 *
	 * @var RenameDutchSchemaSlugDecisions
	 */
	private RenameDutchSchemaSlugDecisions $decisions;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->decisions = new RenameDutchSchemaSlugDecisions();

	}//end setUp()

	/**
	 * A slug present on the install is renamed; one that is absent is skipped.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testPlanRenamesOnlyWhatIsPresent(): void {
		$plan = $this->decisions->plan(
			['catalogus' => 'catalog', 'voorstel' => 'proposal'],
			['catalogus', 'module']
		);

		self::assertSame(['catalogus' => 'catalog'], $plan['renames']);
		self::assertSame([], $plan['refused'], 'an absent slug is nothing to do, not a refusal');

	}//end testPlanRenamesOnlyWhatIsPresent()

	/**
	 * A target that already exists refuses the rename rather than merging.
	 *
	 * Two schemas cannot share a slug, and combining them is a decision about
	 * data — never something a repair step should do on its own.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testPlanRefusesWhenTheTargetSlugExists(): void {
		$plan = $this->decisions->plan(
			['catalogus' => 'catalog'],
			['catalogus', 'catalog']
		);

		self::assertSame([], $plan['renames']);
		self::assertArrayHasKey('catalogus', $plan['refused']);
		self::assertStringContainsString('catalog', $plan['refused']['catalogus']);

	}//end testPlanRefusesWhenTheTargetSlugExists()

	/**
	 * A rename earlier in the map is visible to a later collision check.
	 *
	 * Without carrying the freshly taken name forward, two entries aiming at the
	 * same target would both read as safe and the second would collide at the
	 * database rather than here.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testPlanSeesItsOwnEarlierRenames(): void {
		$plan = $this->decisions->plan(
			['catalogus' => 'catalog', 'catalogus2' => 'catalog'],
			['catalogus', 'catalogus2']
		);

		self::assertSame(['catalogus' => 'catalog'], $plan['renames']);
		self::assertArrayHasKey('catalogus2', $plan['refused']);

	}//end testPlanSeesItsOwnEarlierRenames()

	/**
	 * Schema ids are read out of the registers' JSON column defensively.
	 *
	 * A null, a malformed value or a non-numeric entry must yield no ids rather
	 * than a fatal: this runs inside a repair step, where an exception aborts
	 * the upgrade.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testSchemaIdsFromToleratesMalformedRows(): void {
		$ids = $this->decisions->schemaIdsFrom([
			['schemas' => '[34,35,35]'],
			['schemas' => '[40,"not-a-number",null]'],
			['schemas' => 'not json at all'],
			['schemas' => null],
			[],
		]);

		self::assertSame([34, 35, 40], $ids, 'deduplicated, numeric only, no fatal');

	}//end testSchemaIdsFromToleratesMalformedRows()


	/**
	 * Every target in the shipped map is English and distinct.
	 *
	 * A duplicate target would mean two schemas aimed at one name; a target that
	 * is also somebody else's OLD name would mean the order of the map decides
	 * the outcome.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testShippedMapTargetsAreDistinctAndDoNotCollideWithSources(): void {
		$map = RenameDutchSchemaSlugs::SLUG_MAP;

		self::assertSame(
			count($map),
			count(array_unique(array_values($map))),
			'two slugs must not target one name'
		);

		$sources = array_keys($map);
		foreach ($map as $old => $new) {
			self::assertNotContains(
				$new,
				$sources,
				sprintf("target '%s' is also a source slug, so the map order would decide the result", $new)
			);
		}

	}//end testShippedMapTargetsAreDistinctAndDoNotCollideWithSources()
	/**
	 * The IN-clause placeholder list matches the parameter count.
	 *
	 * A mismatch between placeholders and bound parameters only shows up at
	 * runtime, inside a repair step, on somebody else's install.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testPlaceholdersMatchTheParameterCount(): void {
		self::assertSame('?,?,?', $this->decisions->placeholders(3));
		self::assertSame('?', $this->decisions->placeholders(1));
		self::assertSame('', $this->decisions->placeholders(0), 'an empty IN list must not emit a stray ?');
		self::assertSame('', $this->decisions->placeholders(-1), 'a negative count is not a crash');

	}//end testPlaceholdersMatchTheParameterCount()

	/**
	 * The shipped step names itself and its map is well formed.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testShippedStepNamesItself(): void {
		$step = (new \ReflectionClass(RenameDutchSchemaSlugs::class))->newInstanceWithoutConstructor();
		self::assertNotSame('', $step->getName());
		self::assertStringContainsString('slug', strtolower($step->getName()));

	}//end testShippedStepNamesItself()
	/**
	 * Slugs are read out of schema rows defensively.
	 *
	 * A null slug must yield an empty string, not a TypeError inside a repair
	 * step where an exception aborts the upgrade.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testSlugsFromToleratesMissingSlugs(): void {
		self::assertSame(
			['catalogus', '', 'module'],
			$this->decisions->slugsFrom([
				['id' => 1, 'slug' => 'catalogus'],
				['id' => 2, 'slug' => null],
				['id' => 3, 'slug' => 'module'],
			])
		);
		self::assertSame([], $this->decisions->slugsFrom([]));

	}//end testSlugsFromToleratesMissingSlugs()
	/**
	 * With no registers resolvable the step reports it and renames nothing.
	 *
	 * The failure mode this guards is the opposite of a crash: a repair step
	 * that cannot read the registers must say so and stop, not proceed against
	 * an empty id list and issue an UPDATE with an empty IN clause.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testNoRegistersIsANoOp(): void {
		// An EMPTY result, not a thrown exception: the step catches
		// OCP\DB\Exception specifically, so a RuntimeException from a mock
		// escapes the try/catch and the test measures the mock rather than the
		// step. The mirror of that trap — a mock throwing a type the step DOES
		// catch — is how a broken step once read as a green no-op.
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);
		$db->expects(self::never())->method('executeStatement');

		$step = new RenameDutchSchemaSlugs($db, $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('nothing to do'));

		$step->run($output);

	}//end testNoRegistersIsANoOp()

	/**
	 * The step is a repair step and its map is non-empty snake-free slugs.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function testShippedMapIsWellFormed(): void {
		$map = RenameDutchSchemaSlugs::SLUG_MAP;
		self::assertNotSame([], $map);

		foreach ($map as $old => $new) {
			self::assertNotSame($old, $new, "`$old` maps to itself");
			self::assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9-]*$/', (string)$old);
			self::assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9-]*$/', (string)$new);
		}

		self::assertTrue(
			(new \ReflectionClass(RenameDutchSchemaSlugs::class))->implementsInterface(\OCP\Migration\IRepairStep::class)
		);

	}//end testShippedMapIsWellFormed()
}//end class
