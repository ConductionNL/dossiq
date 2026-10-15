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
		if ($match !== []) {
			return $this->findBy(objectService: $objectService, register: $register, schema: $schema, properties: $match, row: $row);
		}

		$filters = $this->filtersFor(schemaKey: $schemaKey, row: $row, caseTypes: $caseTypes);
		if ($filters === []) {
			return null;
		}

		// Read unscoped: an install runs as a repair step, without a user, and
		// a scoped read there answers "none" and would duplicate the row.
		$found = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: $filters,
		);
		if ($found === [] && $schemaKey === self::CASE_TYPE_KEY) {
			$found = $this->withoutIdentifier(objectService: $objectService, register: $register, schema: $schema, row: $row);
		}

		if ($found === []) {
			return null;
		}

		if (count($found) > 1) {
			$this->logger->warning(
				'Package install: {count} rows in {schema} match {filters}; adopting the first',
				['count' => count($found), 'schema' => $schema, 'filters' => json_encode($filters)]
			);
		}

		return $found[0];
	}//end find()

	/**
	 * The row this instance holds with the same value for one property.
	 *
	 * A seed file may name the properties its rows are recognised by (`match`).
	 * That is how a row outside any case type is adopted: a row a seeder, a
	 * migration or an administrator wrote under another id. A row is the one
	 * when it is equal on every property named. Matching on a flag alone
	 * adopts whichever row has the flag set, whatever it is called.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 * @param array<int, string>   $properties    The properties to match on.
	 * @param array<string, mixed> $row           The package row.
	 *
	 * @return array<string, mixed>|null The row held here, or null.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	private function findBy(object $objectService, string $register, string $schema, array $properties, array $row): ?array {
		$rules = new PackageSeedRules();
		$wanted = [];
		foreach ($properties as $property) {
			$value = ($row[$property] ?? null);
			if ($rules->isMatchable(value: $value) === false) {
				return null;
			}

			$wanted[$property] = $value;
		}

		// The store is asked for the text values only. How it compares a
		// number or a flag in a filter is its own business, and an answer
		// that is too narrow would write a second row; every property is
		// compared here, on the rows it answers.
		$filters = array_filter($wanted, 'is_string');
		$filters['_limit'] = self::MATCH_LIMIT;
		$found = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: $filters,
		);

		// The store may answer a wider match than asked; only equal values count.
		$found = array_values(
			array_filter(
				$found,
				function (array $existing) use ($wanted): bool {
					foreach ($wanted as $property => $value) {
						if ($this->isSame(held: ($existing[$property] ?? null), shipped: $value) === false) {
							return false;
						}
					}

					return true;
				}
			)
		);
		if ($found === []) {
			return null;
		}

		if (count($found) > 1) {
			$this->logger->warning(
				'Package install: {count} rows in {schema} have {values}; adopting the first',
				['count' => count($found), 'schema' => $schema, 'values' => json_encode($wanted)]
			);
		}

		return $found[0];
	}//end findBy()

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
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The case type schema.
	 * @param array<string, mixed> $row           The package case type.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 */
	private function withoutIdentifier(object $objectService, string $register, string $schema, array $row): array {
		$title = trim((string)($row['title'] ?? ''));
		if ($title === '') {
			return [];
		}

		$found = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['title' => $title],
		);

		return array_values(
			array_filter(
				$found,
				static fn (array $existing): bool => trim((string)($existing['identifier'] ?? '')) === ''
			)
		);
	}//end withoutIdentifier()

	/**
	 * The equality filters that identify the row, or none when it cannot be matched.
	 *
	 * @param string               $schemaKey The settings key naming the schema.
	 * @param array<string, mixed> $row       The package row.
	 * @param array<int, string>   $caseTypes The ids of case types adopted this run.
	 *
	 * @return array<string, string> The filters.
	 */
	private function filtersFor(string $schemaKey, array $row, array $caseTypes): array {
		if ($schemaKey === self::CASE_TYPE_KEY) {
			$identifier = trim((string)($row['identifier'] ?? ''));
			if ($identifier === '') {
				return [];
			}

			return ['identifier' => $identifier];
		}

		$caseType = ($row['caseType'] ?? '');
		if (is_string($caseType) === false || in_array($caseType, $caseTypes, true) === false) {
			return [];
		}

		foreach (self::NAMING_FIELDS as $field) {
			$value = ($row[$field] ?? '');
			if (is_string($value) === true && trim($value) !== '') {
				return ['caseType' => $caseType, $field => $value];
			}
		}

		return [];
	}//end filtersFor()
}//end class
