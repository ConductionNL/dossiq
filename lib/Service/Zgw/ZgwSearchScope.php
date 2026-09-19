<?php

/**
 * Dossiq ZGW search scope.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * Whether a ZGW mapping's register and schema can actually be searched.
 *
 * 🔴 A SEARCH SCOPE OPENREGISTER CANNOT RESOLVE ANSWERS EXACTLY LIKE AN EMPTY
 * REGISTER. `ObjectService::buildSearchQuery()` writes `@self.register` and
 * `@self.schema` with a hard `(int)` cast, so a slug, a uuid or the empty
 * string all become `0`. `MagicMapper::searchObjectsPaginated()` then takes the
 * single-schema branch (`0` is not null), fails to load register or schema `0`,
 * logs one warning and returns `['results' => [], 'total' => 0]`. The caller
 * gets a well-formed page saying there is nothing there.
 *
 * The same two values are slug-tolerant on the WRITE side: `saveObject()` and
 * `find()` take `Register|Schema|string|int`, resolve a slug, and throw when
 * they cannot. So one unresolved mapping makes writes fail loudly and reads
 * answer "none", which is the asymmetry that hides it.
 *
 * A dossiq ZGW mapping stores numeric ids ({@see \OCA\Dossiq\Service\Settings\SchemaKeyReconciler}),
 * but `sourceSchema` is written from the `<x>_schema` setting AT THE MOMENT the
 * mapping is first created, so a mapping written before its schema was imported
 * keeps an empty `sourceSchema` for the life of the instance
 * ({@see \OCA\Dossiq\Repair\LoadDefaultZgwMappings::healEmptySourceSchemas()}
 * heals only the ones whose default now resolves).
 *
 * This value object is the one place that says whether a mapping's scope is
 * searchable. Callers whose "no rows" answer drives a deletion, a write or a
 * permission decision ask it first and refuse rather than act on a zero they
 * cannot trust.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class ZgwSearchScope {
	/**
	 * Constructor.
	 *
	 * @param int $register The resolved register id.
	 * @param int $schema   The resolved schema id.
	 */
	private function __construct(
		public readonly int $register,
		public readonly int $schema,
	) {
	}//end __construct()

	/**
	 * Read a searchable scope off a ZGW mapping configuration.
	 *
	 * @param array<string, mixed>|null $mappingConfig The stored ZGW mapping, or null.
	 *
	 * @return self|null The scope, or null when it cannot be searched.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public static function fromMapping(?array $mappingConfig): ?self {
		if ($mappingConfig === null) {
			return null;
		}

		$register = self::toObjectStoreId(reference: ($mappingConfig['sourceRegister'] ?? null));
		$schema = self::toObjectStoreId(reference: ($mappingConfig['sourceSchema'] ?? null));
		if ($register === null || $schema === null) {
			return null;
		}

		return new self(register: $register, schema: $schema);
	}//end fromMapping()

	/**
	 * Whether a ZGW mapping can be searched at all.
	 *
	 * @param array<string, mixed>|null $mappingConfig The stored ZGW mapping, or null.
	 *
	 * @return bool True when both references survive `buildSearchQuery()`'s int cast.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public static function isSearchable(?array $mappingConfig): bool {
		return self::fromMapping(mappingConfig: $mappingConfig) !== null;
	}//end isSearchable()

	/**
	 * Coerce one register/schema reference to the id a search will really use.
	 *
	 * Only a positive-integer reference survives `buildSearchQuery()`. A slug,
	 * a uuid, the empty string, `null` and `"0"` all arrive at the mapper as
	 * `0`, which resolves to no register and no schema.
	 *
	 * @param mixed $reference The stored reference.
	 *
	 * @return int|null The id, or null when the reference is not searchable.
	 */
	private static function toObjectStoreId(mixed $reference): ?int {
		if (is_int($reference) === true) {
			if ($reference > 0) {
				return $reference;
			}

			return null;
		}

		if (is_string($reference) === false) {
			return null;
		}

		$trimmed = trim($reference);
		if ($trimmed === '' || ctype_digit($trimmed) === false) {
			return null;
		}

		$id = (int)$trimmed;
		if ($id <= 0) {
			return null;
		}

		return $id;
	}//end toObjectStoreId()
}//end class
