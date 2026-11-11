<?php

/**
 * Dossiq existing row matcher.
 *
 * Finds the row an instance already holds for a package row that carries a
 * different id. Seeders wrote case types under random ids; a package that
 * replaces a seeder ships fixed ids, so on an instance where the seeder ran
 * the package's ids are absent while its case types are present. Matching by
 * id alone would write every case type a second time (decision 190).
 *
 * A case type is matched by its identifier. A case type a seeder wrote
 * without an identifier (the VTH seeder keyed them by slug) is matched by its
 * title, and only among rows that carry no identifier, so a case type that
 * names itself otherwise is never taken. Any other row is matched only
 * inside a case type that was matched this run, by the first of identifier,
 * name, title or key it carries, so two case types that both name a status
 * "Received" never adopt each other's rows.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Finds the existing row a package row stands for.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class ExistingRowMatcher {

	use SearchesObjects;

	/**
	 * The settings key of the schema whose rows are matched by identifier.
	 *
	 * @var string
	 */
	public const CASE_TYPE_KEY = 'case_type_schema';

	/**
	 * The fields that name a row inside its case type, in the order tried.
	 *
	 * @var array<int, string>
	 */
	private const NAMING_FIELDS = ['identifier', 'name', 'title', 'key'];

	/**
	 * How many rows are read when looking for the one a seed file matches.
	 */
	private const MATCH_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Where an ambiguous match is reported.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The row this instance holds for a package row, or null.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema the row goes to.
	 * @param string               $schemaKey     The settings key naming that schema.
	 * @param array<string, mixed> $row           The package row, its references already
	 *                                            pointing at ids adopted this run.
	 * @param array<int, string>   $caseTypes     The ids of case types adopted this run.
	 * @param array<int, string>   $match         The properties the row's seed file matches on, when it names any.
	 *
	 * @return array<string, mixed>|null The existing row.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function find(
		object $objectService,
		string $register,
		string $schema,
		string $schemaKey,
		array $row,
		array $caseTypes,
		array $match = [],
	): ?array {
		$run = new RowMatchRun(objectService: $objectService, register: $register, schema: $schema, schemaKey: $schemaKey, row: $row);
		if ($match !== []) {
			return $this->findBy(run: $run, properties: $match);
		}

		return $this->findByName(run: $run, caseTypes: $caseTypes);
	}//end find()

	/**
	 * The row held here under the package row's identifier or name.
	 *
	 * @param RowMatchRun        $run       The package row and where to look.
	 * @param array<int, string> $caseTypes The ids of case types adopted this run.
	 *
	 * @return array<string, mixed>|null The existing row.
	 */
	private function findByName(RowMatchRun $run, array $caseTypes): ?array {
		$filters = $this->filtersFor(run: $run, caseTypes: $caseTypes);
		if ($filters === []) {
			return null;
		}

		// Read unscoped: an install runs as a repair step, without a user, and
		// a scoped read there answers "none" and would duplicate the row.
		$found = $this->search(run: $run, filters: $filters);
		if ($found === [] && $run->schemaKey === self::CASE_TYPE_KEY) {
			$found = $this->withoutIdentifier(run: $run);
		}

		if ($found === []) {
			return null;
		}

		if (count($found) > 1) {
			$this->logger->warning(
				'Package install: {count} rows in {schema} match {filters}; adopting the first',
				['count' => count($found), 'schema' => $run->schema, 'filters' => json_encode($filters)]
			);
		}

		return $found[0];
	}//end findByName()

	/**
	 * The row this instance holds with the same value for one property.
	 *
	 * A seed file may name the properties its rows are recognised by (`match`).
	 * That is how a row outside any case type is adopted: a row a seeder, a
	 * migration or an administrator wrote under another id. A row is the one
	 * when it is equal on every property named. Matching on a flag alone
	 * adopts whichever row has the flag set, whatever it is called.
	 *
	 * @param RowMatchRun        $run        The package row and where to look.
	 * @param array<int, string> $properties The properties to match on.
	 *
	 * @return array<string, mixed>|null The row held here, or null.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	private function findBy(RowMatchRun $run, array $properties): ?array {
		$wanted = $this->wantedValues(row: $run->row, properties: $properties);
		if ($wanted === null) {
			return null;
		}

		return $this->heldWith(run: $run, wanted: $wanted);
	}//end findBy()

	/**
	 * The values the package row carries for the match properties.
	 *
	 * @param array<string, mixed> $row        The package row.
	 * @param array<int, string>   $properties The properties to match on.
	 *
	 * @return array<string, string|int|bool>|null The values, or null when one cannot be matched on.
	 */
	private function wantedValues(array $row, array $properties): ?array {
		$rules = new PackageSeedRules();
		$wanted = [];
		foreach ($properties as $property) {
			$value = ($row[$property] ?? null);
			if ($rules->isMatchable(value: $value) === false) {
				return null;
			}

			$wanted[$property] = $value;
		}

		return $wanted;
	}//end wantedValues()

	/**
	 * The first row held here equal on every wanted value, or null.
	 *
	 * @param RowMatchRun                    $run    The package row and where to look.
	 * @param array<string, string|int|bool> $wanted The values to match.
	 *
	 * @return array<string, mixed>|null The row held here, or null.
	 */
	private function heldWith(RowMatchRun $run, array $wanted): ?array {
		// The store is asked for the text values only. How it compares a
		// number or a flag in a filter is its own business, and an answer
		// that is too narrow would write a second row; every property is
		// compared here, on the rows it answers.
		$filters = array_filter($wanted, 'is_string');
		$filters['_limit'] = self::MATCH_LIMIT;
		$found = $this->equalOn(found: $this->search(run: $run, filters: $filters), wanted: $wanted);
		if ($found === []) {
			return null;
		}

		if (count($found) > 1) {
			$this->logger->warning(
				'Package install: {count} rows in {schema} have {values}; adopting the first',
				['count' => count($found), 'schema' => $run->schema, 'values' => json_encode($wanted)]
			);
		}

		return $found[0];
	}//end heldWith()

	/**
	 * The rows that hold every wanted value.
	 *
	 * The store may answer a wider match than asked; only equal values count.
	 *
	 * @param array<int, array<string, mixed>> $found  The rows the store answered.
	 * @param array<string, string|int|bool>   $wanted The values to match.
	 *
	 * @return array<int, array<string, mixed>> The equal rows.
	 */
	private function equalOn(array $found, array $wanted): array {
		return array_values(
			array_filter(
				$found,
				fn (array $existing): bool => $this->holdsAll(existing: $existing, wanted: $wanted)
			)
		);
	}//end equalOn()

	/**
	 * Whether a row holds every wanted value.
	 *
	 * @param array<string, mixed>           $existing The row held here.
	 * @param array<string, string|int|bool> $wanted   The values to match.
	 *
	 * @return bool True when every value is the same.
	 */
	private function holdsAll(array $existing, array $wanted): bool {
		foreach ($wanted as $property => $value) {
			if ($this->isSame(held: ($existing[$property] ?? null), shipped: $value) === false) {
				return false;
			}
		}

		return true;
	}//end holdsAll()

	/**
	 * Whether the value a row holds is the value the package ships.
	 *
	 * A text is equal or it is not. A flag and a number are compared by what
	 * they mean: a store that answers a flag as 1 or "true" still holds the
	 * flag, and reading it as different would write a second row.
	 *
	 * @param mixed           $held    The value the existing row holds.
	 * @param string|int|bool $shipped The value the package row carries.
	 *
	 * @return bool True when they are the same.
	 */
	private function isSame(mixed $held, string|int|bool $shipped): bool {
		if (is_bool($shipped) === true) {
			if (is_scalar($held) === false) {
				return false;
			}

			return filter_var($held, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === $shipped;
		}

		if (is_int($shipped) === true) {
			return is_numeric($held) === true && (string)$held === (string)$shipped;
		}

		return $held === $shipped;
	}//end isSame()

	/**
	 * The case types with the package row's title that carry no identifier.
	 *
	 * @param RowMatchRun $run The package case type and where to look.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 */
	private function withoutIdentifier(RowMatchRun $run): array {
		$title = trim((string)($run->row['title'] ?? ''));
		if ($title === '') {
			return [];
		}

		$found = $this->search(run: $run, filters: ['title' => $title]);

		return array_values(
			array_filter(
				$found,
				static fn (array $existing): bool => trim((string)($existing['identifier'] ?? '')) === ''
			)
		);
	}//end withoutIdentifier()

	/**
	 * The rows in the run's schema that the store answers for the filters, unscoped.
	 *
	 * @param RowMatchRun          $run     Where to look.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function search(RowMatchRun $run, array $filters): array {
		return $this->searchObjectsAsArraysUnscoped(
			objectService: $run->objectService,
			register: $run->register,
			schema: $run->schema,
			filters: $filters,
		);
	}//end search()

	/**
	 * The equality filters that identify the row, or none when it cannot be matched.
	 *
	 * @param RowMatchRun        $run       The package row and the schema key.
	 * @param array<int, string> $caseTypes The ids of case types adopted this run.
	 *
	 * @return array<string, string> The filters.
	 */
	private function filtersFor(RowMatchRun $run, array $caseTypes): array {
		if ($run->schemaKey === self::CASE_TYPE_KEY) {
			return $this->identifierFilter(row: $run->row);
		}

		return $this->namedInCaseType(row: $run->row, caseTypes: $caseTypes);
	}//end filtersFor()

	/**
	 * The filter on a case type's identifier, or none when it carries none.
	 *
	 * @param array<string, mixed> $row The package case type.
	 *
	 * @return array<string, string> The filter.
	 */
	private function identifierFilter(array $row): array {
		$identifier = trim((string)($row['identifier'] ?? ''));
		if ($identifier === '') {
			return [];
		}

		return ['identifier' => $identifier];
	}//end identifierFilter()

	/**
	 * The filters on a row's case type and name, or none when it cannot be matched.
	 *
	 * @param array<string, mixed> $row       The package row.
	 * @param array<int, string>   $caseTypes The ids of case types adopted this run.
	 *
	 * @return array<string, string> The filters.
	 */
	private function namedInCaseType(array $row, array $caseTypes): array {
		$caseType = ($row['caseType'] ?? '');
		if (is_string($caseType) === false || in_array($caseType, $caseTypes, true) === false) {
			return [];
		}

		$name = $this->firstName(row: $row);
		if ($name === null) {
			return [];
		}

		return ['caseType' => $caseType] + $name;
	}//end namedInCaseType()

	/**
	 * The first naming field the row carries with its value, or null.
	 *
	 * @param array<string, mixed> $row The package row.
	 *
	 * @return array<string, string>|null The field and its value.
	 */
	private function firstName(array $row): ?array {
		foreach (self::NAMING_FIELDS as $field) {
			$value = ($row[$field] ?? '');
			if (is_string($value) === true && trim($value) !== '') {
				return [$field => $value];
			}
		}

		return null;
	}//end firstName()
}//end class
