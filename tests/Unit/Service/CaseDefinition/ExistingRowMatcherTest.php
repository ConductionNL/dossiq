<?php

/**
 * Pins how an existing row is found for a package row.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\ExistingRowMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Unit tests for ExistingRowMatcher.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\ExistingRowMatcher
 */
class ExistingRowMatcherTest extends TestCase {

	/**
	 * The warnings logged, as message and context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The searches asked of the store, as schema, filters and scope.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $searches = [];

	/**
	 * A store answering canned rows per title or identifier filter.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows held.
	 *
	 * @return object The fake object service.
	 */
	private function store(array $rows): object {
		$test = $this;
		return new class ($rows, $test) {
			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $rows The rows.
			 * @param ExistingRowMatcherTest           $test The test recording calls.
			 */
			public function __construct(private array $rows, private ExistingRowMatcherTest $test) {
			}

			/**
			 * Answer every held row carrying each text filter.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 * @param bool                 ...$scope The scope.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool ...$scope): array {
				$this->test->recordSearch([$register, $schema, $filters, $scope]);
				return array_values(
					array_filter(
						$this->rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if ($key !== '_limit' && ($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		};
	}//end store()

	/**
	 * Record a search the fake store answered.
	 *
	 * @param array<int, mixed> $search The call.
	 *
	 * @return void
	 */
	public function recordSearch(array $search): void {
		$this->searches[] = $search;
	}//end recordSearch()

	/**
	 * The matcher under test, logging into this test.
	 *
	 * @return ExistingRowMatcher The matcher.
	 */
	private function matcher(): ExistingRowMatcher {
		$test = $this;
		$logger = new class ($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param ExistingRowMatcherTest $test The test.
			 */
			public function __construct(private ExistingRowMatcherTest $test) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->recordWarning([(string)$level . ':' . (string)$message, $context]);
			}
		};
		return new ExistingRowMatcher(logger: $logger);
	}//end matcher()

	/**
	 * Record a warning.
	 *
	 * @param array{0: string, 1: array<string, mixed>} $warning The warning.
	 *
	 * @return void
	 */
	public function recordWarning(array $warning): void {
		$this->warnings[] = $warning;
	}//end recordWarning()

	/**
	 * A case type is found by its identifier, unscoped.
	 *
	 * @return void
	 */
	public function testCaseTypeIsFoundByIdentifier(): void {
		$store = $this->store([['id' => 'a', 'identifier' => 'X'], ['id' => 'b', 'identifier' => 'Y']]);
		$found = $this->matcher()->find(
			objectService: $store,
			register: 'reg',
			schema: 'ct',
			schemaKey: ExistingRowMatcher::CASE_TYPE_KEY,
			row: ['identifier' => ' Y ', 'title' => 'T'],
			caseTypes: [],
		);

		self::assertSame('b', $found['id']);
		self::assertSame([['reg', 'ct', ['identifier' => 'Y'], ['_rbac' => false, '_multitenancy' => false]]], $this->searches);
		self::assertSame([], $this->warnings);
	}//end testCaseTypeIsFoundByIdentifier()

	/**
	 * A case type without identifier match falls back to a title among rows without one.
	 *
	 * @return void
	 */
	public function testCaseTypeFallsBackToTitleAmongRowsWithoutIdentifier(): void {
		$store = $this->store(
			[
				['id' => 'named', 'title' => 'Permit', 'identifier' => 'OTHER'],
				['id' => 'bare', 'title' => 'Permit'],
				['id' => 'bare2', 'title' => 'Permit', 'identifier' => ' '],
			]
		);
		$found = $this->matcher()->find(
			objectService: $store,
			register: 'reg',
			schema: 'ct',
			schemaKey: ExistingRowMatcher::CASE_TYPE_KEY,
			row: ['identifier' => 'NEW', 'title' => ' Permit '],
			caseTypes: [],
		);

		self::assertSame('bare', $found['id']);
		self::assertSame(['identifier' => 'NEW'], $this->searches[0][2]);
		self::assertSame(['title' => 'Permit'], $this->searches[1][2]);
		self::assertCount(1, $this->warnings);
		self::assertSame('warning:Package install: {count} rows in {schema} match {filters}; adopting the first', $this->warnings[0][0]);
		self::assertSame(['count' => 2, 'schema' => 'ct', 'filters' => '{"identifier":"NEW"}'], $this->warnings[0][1]);
	}//end testCaseTypeFallsBackToTitleAmongRowsWithoutIdentifier()

	/**
	 * A case type without identifier or title is not matched, and without identifier no search runs.
	 *
	 * @return void
	 */
	public function testCaseTypeWithoutIdentifierOrTitleIsNotMatched(): void {
		$store = $this->store([['id' => 'a', 'title' => 'T']]);
		$matcher = $this->matcher();

		self::assertNull($matcher->find(objectService: $store, register: 'reg', schema: 'ct', schemaKey: ExistingRowMatcher::CASE_TYPE_KEY, row: ['title' => 'T'], caseTypes: []));
		self::assertSame([], $this->searches);
		self::assertNull($matcher->find(objectService: $store, register: 'reg', schema: 'ct', schemaKey: ExistingRowMatcher::CASE_TYPE_KEY, row: ['identifier' => 'Q', 'title' => ' '], caseTypes: []));
		self::assertCount(1, $this->searches);
	}//end testCaseTypeWithoutIdentifierOrTitleIsNotMatched()

	/**
	 * A row inside a case type is matched by the first naming field it carries.
	 *
	 * @return void
	 */
	public function testRowInsideAdoptedCaseTypeIsMatchedByFirstNamingField(): void {
		$store = $this->store(
			[
				['id' => 's1', 'caseType' => 'ct1', 'name' => 'Received'],
				['id' => 's2', 'caseType' => 'ct2', 'name' => 'Received'],
			]
		);
		$found = $this->matcher()->find(
			objectService: $store,
			register: 'reg',
			schema: 'status',
			schemaKey: 'status_schema',
			row: ['caseType' => 'ct2', 'identifier' => '  ', 'name' => 'Received', 'title' => 'Ignored'],
			caseTypes: ['ct1', 'ct2'],
		);

		self::assertSame('s2', $found['id']);
		self::assertSame(['caseType' => 'ct2', 'name' => 'Received'], $this->searches[0][2]);
	}//end testRowInsideAdoptedCaseTypeIsMatchedByFirstNamingField()

	/**
	 * A row outside an adopted case type, or without a name, is not searched.
	 *
	 * @return void
	 */
	public function testRowOutsideAdoptedCaseTypeOrUnnamedIsNotSearched(): void {
		$store = $this->store([['id' => 's1', 'caseType' => 'ct1', 'key' => 'k']]);
		$matcher = $this->matcher();

		self::assertNull($matcher->find(objectService: $store, register: 'reg', schema: 'status', schemaKey: 'status_schema', row: ['caseType' => 'ct9', 'key' => 'k'], caseTypes: ['ct1']));
		self::assertNull($matcher->find(objectService: $store, register: 'reg', schema: 'status', schemaKey: 'status_schema', row: ['caseType' => ['ct1'], 'key' => 'k'], caseTypes: ['ct1']));
		self::assertNull($matcher->find(objectService: $store, register: 'reg', schema: 'status', schemaKey: 'status_schema', row: ['caseType' => 'ct1', 'key' => 5], caseTypes: ['ct1']));
		self::assertSame([], $this->searches);

		$found = $matcher->find(objectService: $store, register: 'reg', schema: 'status', schemaKey: 'status_schema', row: ['caseType' => 'ct1', 'key' => 'k'], caseTypes: ['ct1']);
		self::assertSame('s1', $found['id']);
		self::assertNull($matcher->find(objectService: $store, register: 'reg', schema: 'status', schemaKey: 'status_schema', row: ['caseType' => 'ct1', 'key' => 'zz'], caseTypes: ['ct1']));
	}//end testRowOutsideAdoptedCaseTypeOrUnnamedIsNotSearched()

	/**
	 * A seed file's match properties pick the row equal on every one.
	 *
	 * @return void
	 */
	public function testMatchPropertiesPickTheRowEqualOnEveryOne(): void {
		$store = $this->store(
			[
				['id' => 'r1', 'code' => 'A', 'default' => 'false', 'rank' => '2'],
				['id' => 'r2', 'code' => 'A', 'default' => 1, 'rank' => 2],
				['id' => 'r3', 'code' => 'A', 'default' => 'yes', 'rank' => '2'],
				['id' => 'r4', 'code' => 'A', 'default' => ['x'], 'rank' => 2],
				['id' => 'r5', 'code' => 'A', 'default' => true, 'rank' => 'two'],
			]
		);
		$found = $this->matcher()->find(
			objectService: $store,
			register: 'reg',
			schema: 'tpl',
			schemaKey: 'whatever',
			row: ['code' => 'A', 'default' => true, 'rank' => 2],
			caseTypes: [],
			match: ['code', 'default', 'rank'],
		);

		self::assertSame('r2', $found['id']);
		self::assertSame(['code' => 'A', '_limit' => 500], $this->searches[0][2]);
		self::assertCount(1, $this->warnings);
		self::assertSame('warning:Package install: {count} rows in {schema} have {values}; adopting the first', $this->warnings[0][0]);
		self::assertSame(['count' => 2, 'schema' => 'tpl', 'values' => '{"code":"A","default":true,"rank":2}'], $this->warnings[0][1]);
	}//end testMatchPropertiesPickTheRowEqualOnEveryOne()

	/**
	 * A text property is compared strictly, and an unmatchable value matches nothing.
	 *
	 * @return void
	 */
	public function testMatchOnTextIsStrictAndUnmatchableValueMatchesNothing(): void {
		$store = $this->store([['id' => 'r1', 'code' => 'A']]);
		$matcher = $this->matcher();

		self::assertNull($matcher->find(objectService: $store, register: 'reg', schema: 'tpl', schemaKey: 'k', row: ['code' => ' '], caseTypes: [], match: ['code']));
		self::assertNull($matcher->find(objectService: $store, register: 'reg', schema: 'tpl', schemaKey: 'k', row: ['code' => 'A', 'flag' => 1.5], caseTypes: [], match: ['code', 'flag']));
		self::assertSame([], $this->searches);

		self::assertSame('r1', $matcher->find(objectService: $store, register: 'reg', schema: 'tpl', schemaKey: 'k', row: ['code' => 'A'], caseTypes: [], match: ['code'])['id']);
		self::assertNull($matcher->find(objectService: $store, register: 'reg', schema: 'tpl', schemaKey: 'k', row: ['code' => 'B'], caseTypes: [], match: ['code']));
		self::assertSame([], $this->warnings);
	}//end testMatchOnTextIsStrictAndUnmatchableValueMatchesNothing()
}//end class
