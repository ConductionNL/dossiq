<?php

/**
 * Dossiq Case Type Resolver.
 *
 * The effective blueprint of a case type that derives from a parent.
 *
 * `caseType.parentCaseType` says a type inherits its parent's statuses,
 * results, properties and deadlines and declares only what differs. Nothing in
 * OpenRegister expresses that: a `$ref` links two rows, it does not merge them,
 * and there is no declarative merge over a reference. So this is the one piece
 * of code the change carries (design D2), and it is deliberately small — it
 * reads, it merges, it refuses a cycle, and it writes nothing.
 *
 * 🔴 EVERY READER MUST COME HERE. A caller that still asks the store for
 * `statusType where caseType = X` gets the child's OWN rows, which for a child
 * that declares none is an empty list — a case type with no statuses, no error,
 * and no way to move a case. That is why `StatusTypeLookup` and the case page's
 * stepper read through this class rather than beside it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use RuntimeException;

/**
 * Resolves the effective blueprint of a case type and its ancestors.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeResolver {
	/**
	 * How deep a chain of parents may run, the type itself included.
	 *
	 * Three, per design D2. The cap is not a performance guard: it is what
	 * stops a chain nobody can hold in their head, and what bounds the reads a
	 * single page does. A deeper chain resolves over the first three levels and
	 * the rest is ignored, which is visible on the page rather than silent —
	 * `chainFor()` returns exactly what was used.
	 */
	public const MAX_DEPTH = 3;

	/**
	 * Marks a row the type declares itself.
	 */
	public const ORIGIN_OWN = 'own';

	/**
	 * Marks a row that came from an ancestor.
	 */
	public const ORIGIN_INHERITED = 'inherited';

	/**
	 * Marks a row that belongs to no type at all.
	 */
	public const ORIGIN_SHARED = 'shared';

	/**
	 * The fields a child falls back to its parent for when it leaves them empty.
	 *
	 * Only fields whose emptiness is unambiguous. `isDraft` is not here on
	 * purpose: false is a real answer, not a missing one, so inheriting it
	 * would publish a child the moment its parent was published.
	 *
	 * @var array<int, string>
	 */
	private const INHERITED_FIELDS = [
		'initialStatus',
		'processingDeadline',
		'extensionPeriod',
		'suspensionAllowed',
		'extensionAllowed',
		'processesPersonalData',
		'personalDataCategories',
		'legalBasis',
		'verwerkingsactiviteit',
	];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore $store Every OpenRegister read this resolver performs.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
	) {
	}//end __construct()

	/**
	 * The chain of case types from one type up to its root ancestor.
	 *
	 * Nearest first: index 0 is the type itself. A chain that returns to a type
	 * already in it stops there rather than looping, so a cycle already stored
	 * by an older version of the app degrades to a finite answer instead of
	 * hanging the request that reads it.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows, the type itself first.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function chainFor(string $caseTypeId): array {
		$chain = [];
		$seen = [];
		$current = trim($caseTypeId);

		$depth = 0;
		while ($current !== '' && $depth < self::MAX_DEPTH) {
			if (isset($seen[$current]) === true) {
				break;
			}

			$row = $this->store->readCaseType(caseTypeId: $current);
			if ($row === []) {
				break;
			}

			$seen[$current] = true;
			$chain[] = $row;
			$depth++;
			$current = $this->store->referenceId(value: ($row['parentCaseType'] ?? ''));
		}

		return $chain;
	}//end chainFor()

	/**
	 * The effective case type: its own fields, with the parent's where it is silent.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, mixed> The merged row, or an empty array when unreadable.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function effectiveCaseType(string $caseTypeId): array {
		$chain = $this->chainFor(caseTypeId: $caseTypeId);
		if ($chain === []) {
			return [];
		}

		$effective = array_shift($chain);
		foreach ($chain as $ancestor) {
			foreach (self::INHERITED_FIELDS as $field) {
				if ($this->isEmptyValue(value: ($effective[$field] ?? null)) === false) {
					continue;
				}

				if ($this->isEmptyValue(value: ($ancestor[$field] ?? null)) === true) {
					continue;
				}

				$effective[$field] = $ancestor[$field];
			}
		}

		return $effective;
	}//end effectiveCaseType()

	/**
	 * The statuses a case of this type can be in.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows, each carrying `origin`.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function statusTypesFor(string $caseTypeId): array {
		return $this->inheritedRows(
			caseTypeId: $caseTypeId,
			schemaKey: 'status_type_schema',
			includeShared: false
		);
	}//end statusTypesFor()

	/**
	 * The results a case of this type can close with.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows, each carrying `origin`.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function resultTypesFor(string $caseTypeId): array {
		return $this->inheritedRows(
			caseTypeId: $caseTypeId,
			schemaKey: 'result_type_schema',
			includeShared: false
		);
	}//end resultTypesFor()

	/**
	 * The attributes a case of this type carries.
	 *
	 * Shared attributes are included: a `propertyDefinition` saved without a
	 * `caseType` belongs to every type, which is what makes one attribute
	 * reusable instead of copied per type. They are marked `shared` rather than
	 * `inherited`, because they came from no ancestor and a reader who is
	 * looking for what the PARENT contributed should not find them there.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows, each carrying `origin`.
	 *
	 * @spec openspec/specs/property-definition-management/spec.md
	 */
	public function propertyDefinitionsFor(string $caseTypeId): array {
		return $this->inheritedRows(
			caseTypeId: $caseTypeId,
			schemaKey: 'property_definition_schema',
			includeShared: true
		);
	}//end propertyDefinitionsFor()

	/**
	 * The whole blueprint, for the one page that shows all of it.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, mixed> The blueprint: `caseType` (the effective
	 *                              row), `parents` (the ancestors it merged),
	 *                              `statusTypes`, `resultTypes` and
	 *                              `propertyDefinitions`.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function blueprintFor(string $caseTypeId): array {
		$chain = $this->chainFor(caseTypeId: $caseTypeId);

		return [
			'caseType' => $this->effectiveCaseType(caseTypeId: $caseTypeId),
			'parents' => array_slice($chain, 1),
			'statusTypes' => $this->statusTypesFor(caseTypeId: $caseTypeId),
			'resultTypes' => $this->resultTypesFor(caseTypeId: $caseTypeId),
			'propertyDefinitions' => $this->propertyDefinitionsFor(caseTypeId: $caseTypeId),
		];
	}//end blueprintFor()

	/**
	 * Whether naming a parent would close a loop.
	 *
	 * @param string $caseTypeId       The type being saved.
	 * @param string $parentCaseTypeId The parent it wants.
	 *
	 * @return boolean True when the parent already descends from the type.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function wouldCycle(string $caseTypeId, string $parentCaseTypeId): bool {
		$self = trim($caseTypeId);
		$parent = trim($parentCaseTypeId);

		if ($self === '' || $parent === '') {
			return false;
		}

		if ($self === $parent) {
			return true;
		}

		foreach ($this->chainFor(caseTypeId: $parent) as $ancestor) {
			if ($this->store->rowId(row: $ancestor) === $self) {
				return true;
			}
		}

		return false;
	}//end wouldCycle()

	/**
	 * Refuse a parent that would close a loop.
	 *
	 * The message names the cycle rather than saying "invalid parent": the
	 * author of a three-deep chain cannot see from the form which link is the
	 * one that closes it.
	 *
	 * @param string $caseTypeId       The type being saved.
	 * @param string $parentCaseTypeId The parent it wants.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the parent descends from the type.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function assertNoCycle(string $caseTypeId, string $parentCaseTypeId): void {
		$names = $this->cycleFor(caseTypeId: $caseTypeId, parentCaseTypeId: $parentCaseTypeId);
		if ($names === []) {
			return;
		}

		throw new RuntimeException(
			'A case type cannot inherit from itself: ' . implode(' -> ', $names)
		);
	}//end assertNoCycle()

	/**
	 * The loop naming a parent would close, as the titles a person reads.
	 *
	 * Shared by the publish path ({@see self::assertNoCycle()}) and the save
	 * path (`CaseTypeParentCycleListener`), so the two refuse the same chains
	 * and name them the same way.
	 *
	 * @param string $caseTypeId       The type being saved.
	 * @param string $parentCaseTypeId The parent it wants.
	 * @param string $selfTitle        The title the type is being saved with,
	 *                                 when the caller has it. A new type has no
	 *                                 stored row to read its title from.
	 *
	 * @return array<int, string> The titles from the type round to itself, or
	 *                            an empty array when there is no loop.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function cycleFor(string $caseTypeId, string $parentCaseTypeId, string $selfTitle = ''): array {
		if ($this->wouldCycle(caseTypeId: $caseTypeId, parentCaseTypeId: $parentCaseTypeId) === false) {
			return [];
		}

		$title = trim($selfTitle);
		if ($title === '') {
			$title = $this->titleOf(caseTypeId: $caseTypeId);
		}

		$names = [$title];
		foreach ($this->chainFor(caseTypeId: $parentCaseTypeId) as $ancestor) {
			if ($this->store->rowId(row: $ancestor) === trim($caseTypeId)) {
				// The loop closes on the type itself: name it by the title it
				// is being saved with, not by the stored one.
				$names[] = $title;
				break;
			}

			$names[] = (string)($ancestor['title'] ?? $this->store->rowId(row: $ancestor));
		}

		if (count($names) === 1) {
			// A type named as its own parent: the loop is one link long.
			$names[] = $title;
		}

		return $names;
	}//end cycleFor()

	/**
	 * Rows of one schema for the whole chain, the nearest declaration winning.
	 *
	 * The child's row replaces the ancestor's when the two share a NAME, which
	 * is the only key both ends can be authored with: ids are minted per
	 * install, so a child could not name the parent row it is overriding.
	 *
	 * @param string  $caseTypeId    CaseType UUID.
	 * @param string  $schemaKey     The settings key naming the schema.
	 * @param boolean $includeShared Whether rows with no case type count.
	 *
	 * @return array<int, array<string, mixed>> The merged rows.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function inheritedRows(string $caseTypeId, string $schemaKey, bool $includeShared): array {
		$chain = $this->chainFor(caseTypeId: $caseTypeId);
		if ($chain === []) {
			return [];
		}

		// Furthest ancestor first, so a nearer declaration overwrites it.
		$merged = [];
		foreach (array_reverse($chain) as $depth => $ancestor) {
			$isOwn = ($depth === (count($chain) - 1));
			$origin = self::ORIGIN_INHERITED;
			if ($isOwn === true) {
				$origin = self::ORIGIN_OWN;
			}

			foreach ($this->store->rowsOfType(schemaKey: $schemaKey, caseTypeId: $this->store->rowId(row: $ancestor)) as $row) {
				$merged[$this->mergeKey(row: $row)] = $this->tag(
					row: $row,
					origin: $origin,
					ancestor: $ancestor
				);
			}
		}

		if ($includeShared === true) {
			foreach ($this->store->sharedRows(schemaKey: $schemaKey) as $row) {
				$key = $this->mergeKey(row: $row);
				if (isset($merged[$key]) === true) {
					continue;
				}

				$merged[$key] = $this->tag(row: $row, origin: self::ORIGIN_SHARED, ancestor: []);
			}
		}

		return $this->sortByOrder(rows: array_values($merged));
	}//end inheritedRows()

	/**
	 * Stamp a row with where it came from.
	 *
	 * @param array<string, mixed> $row      The row.
	 * @param string               $origin   own, inherited or shared.
	 * @param array<string, mixed> $ancestor The case type it was declared on.
	 *
	 * @return array<string, mixed> The row, with `origin` and `originCaseType`.
	 */
	private function tag(array $row, string $origin, array $ancestor): array {
		$row['origin'] = $origin;
		$row['originCaseType'] = $this->store->rowId(row: $ancestor);
		$row['originCaseTypeTitle'] = (string)($ancestor['title'] ?? '');

		return $row;
	}//end tag()

	/**
	 * The key two rows are considered the same by.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The lower-cased, trimmed name, or the id when it has none.
	 */
	private function mergeKey(array $row): string {
		$name = trim((string)($row['name'] ?? ($row['title'] ?? '')));
		if ($name === '') {
			return 'id:' . $this->store->rowId(row: $row);
		}

		return 'name:' . mb_strtolower($name);
	}//end mergeKey()

	/**
	 * Order the rows the way a status lifecycle is read.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, array<string, mixed>> The rows, by `order` then by name.
	 */
	private function sortByOrder(array $rows): array {
		usort(
			$rows,
			function (array $left, array $right): int {
				$byOrder = ((int)($left['order'] ?? 0) <=> (int)($right['order'] ?? 0));
				if ($byOrder !== 0) {
					return $byOrder;
				}

				return strcmp($this->mergeKey(row: $left), $this->mergeKey(row: $right));
			}
		);

		return $rows;
	}//end sortByOrder()

	/**
	 * Whether a value counts as "the child said nothing".
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean True when it is null, an empty string or an empty array.
	 */
	private function isEmptyValue(mixed $value): bool {
		if ($value === null || $value === []) {
			return true;
		}

		if (is_string($value) === true) {
			return (trim($value) === '');
		}

		return false;
	}//end isEmptyValue()



	/**
	 * A case type's title, for a message a person reads.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return string The title, or the id when it is unreadable.
	 */
	private function titleOf(string $caseTypeId): string {
		$row = $this->store->readCaseType(caseTypeId: $caseTypeId);

		return (string)($row['title'] ?? $caseTypeId);
	}//end titleOf()






}//end class
