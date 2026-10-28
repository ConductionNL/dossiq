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

use OCA\Dossiq\Service\CaseType\InheritedRowValues;
use OCA\Dossiq\Service\CaseType\RowOrigins;
use OCA\Dossiq\Service\Support\LanguageMapText;
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
		'defaultImpact',
		'defaultUrgency',
		'priorityMatrix',
		'duplicatePolicy',
	];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore   $store Every OpenRegister read this resolver performs.
	 * @param LanguageMapText $text  How a translatable title or name is read, the same for every reader.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly LanguageMapText $text = new LanguageMapText(),
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
		$values    = new InheritedRowValues();
		foreach ($chain as $ancestor) {
			foreach (self::INHERITED_FIELDS as $field) {
				if ($values->isEmpty(value: ($effective[$field] ?? null)) === false) {
					continue;
				}

				if ($values->isEmpty(value: ($ancestor[$field] ?? null)) === true) {
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
		return ($this->ownLifecycle(caseTypeId: $caseTypeId) ?? $this->inheritedRows(
			caseTypeId: $caseTypeId,
			schemaKey: 'status_type_schema',
			includeShared: false
		));
	}//end statusTypesFor()

	/**
	 * A child type's own statuses when they make a whole lifecycle (a start and
	 * an end: at least one status that is final and one that is not); they then
	 * replace the ancestors', because a parent's Besluitvorming merged in would
	 * add a step the type does not have. A type that declares less (one status
	 * recoloured or renamed) still merges by name, as the schema says.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>>|null The own rows, or null when they merge.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function ownLifecycle(string $caseTypeId): ?array {
		$chain = $this->chainFor(caseTypeId: $caseTypeId);
		if (count($chain) < 2) {
			return null;
		}

		$own  = $chain[0];
		$rows = $this->store->rowsOfType(schemaKey: 'status_type_schema', caseTypeId: $this->store->rowId(row: $own));
		if ((new InheritedRowValues())->isWholeLifecycle(rows: $rows) === false) {
			return null;
		}

		$origin = (new RowOrigins())->originFor(isOwn: true);
		return $this->sortByOrder(rows: array_map(fn (array $row): array => $this->tag(row: $row, origin: $origin, ancestor: $own), array_values($rows)));
	}//end ownLifecycle()

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
	 *                              `statusTypes`, `resultTypes`,
	 *                              `propertyDefinitions` and `counts`.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function blueprintFor(string $caseTypeId): array {
		$chain      = $this->chainFor(caseTypeId: $caseTypeId);
		$properties = $this->propertyDefinitionsFor(caseTypeId: $caseTypeId);

		return [
			'caseType' => $this->effectiveCaseType(caseTypeId: $caseTypeId),
			'parents' => array_slice($chain, 1),
			'statusTypes' => $this->statusTypesFor(caseTypeId: $caseTypeId),
			'resultTypes' => $this->resultTypesFor(caseTypeId: $caseTypeId),
			'propertyDefinitions' => $properties,
			'counts' => ['propertyDefinitions' => (new RowOrigins())->counts(rows: $properties, inheritedOrigin: self::ORIGIN_INHERITED)],
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

			$names[] = $this->text->textOr(value: ($ancestor['title'] ?? null), fallback: $this->store->rowId(row: $ancestor));
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
		$merged  = [];
		$origins = new RowOrigins();
		foreach (array_reverse($chain) as $depth => $ancestor) {
			$isOwn  = ($depth === (count($chain) - 1));
			$origin = $origins->originFor(isOwn: $isOwn);

			foreach ($this->store->rowsOfType(schemaKey: $schemaKey, caseTypeId: $this->store->rowId(row: $ancestor)) as $row) {
				$key    = $this->mergeKey(row: $row);
				$tagged = $this->tag(
					row: $row,
					origin: $origin,
					ancestor: $ancestor
				);
				$merged[$key] = $origins->markOverride(row: $tagged, isOwn: $isOwn, merged: $merged, key: $key);
			}
		}

		if ($includeShared === true) {
			$merged = $this->withSharedRows(merged: $merged, schemaKey: $schemaKey);
		}

		return $this->sortByOrder(rows: array_values($merged));
	}//end inheritedRows()

	/**
	 * Add the rows that belong to no case type, where no type row of the same name stands.
	 *
	 * @param array<string, array<string, mixed>> $merged    The merged rows by key.
	 * @param string                              $schemaKey The settings key naming the schema.
	 *
	 * @return array<string, array<string, mixed>> The rows with the shared ones added.
	 */
	private function withSharedRows(array $merged, string $schemaKey): array {
		foreach ($this->store->sharedRows(schemaKey: $schemaKey) as $row) {
			$key = $this->mergeKey(row: $row);
			if (isset($merged[$key]) === false) {
				$merged[$key] = $this->tag(row: $row, origin: self::ORIGIN_SHARED, ancestor: []);
			}
		}

		return $merged;
	}//end withSharedRows()

	/**
	 * Stamp a row with where it came from.
	 *
	 * @param array<string, mixed> $row      The row.
	 * @param string               $origin   own, inherited or shared.
	 * @param array<string, mixed> $ancestor The case type it was declared on.
	 *
	 * @return array<string, mixed> The row, with `origin` and `originCaseType`.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	private function tag(array $row, string $origin, array $ancestor): array {
		$row['origin'] = $origin;
		$row['originCaseType'] = $this->store->rowId(row: $ancestor);
		$row['originCaseTypeTitle'] = $this->text->textOf(value: ($ancestor['title'] ?? null));

		return $row;
	}//end tag()

	/**
	 * The key two rows are considered the same by.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * `name` and `title` are `translatable`, so a row from `searchObjects()`
	 * carries a language map. Cast to a string, every map read "Array" and
	 * every status of a case type merged into ONE row. The key reads the map
	 * the same way for every reader (Dutch first), so it stays stable.
	 *
	 * @return string The lower-cased, trimmed name, or the id when it has none.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	private function mergeKey(array $row): string {
		$name = $this->text->textOf(value: ($row['name'] ?? ($row['title'] ?? null)));
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
	 * A case type's title, for a message a person reads.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return string The title, or the id when it is unreadable.
	 */
	private function titleOf(string $caseTypeId): string {
		$row = $this->store->readCaseType(caseTypeId: $caseTypeId);

		return $this->text->textOr(value: ($row['title'] ?? null), fallback: $caseTypeId);
	}//end titleOf()






}//end class
