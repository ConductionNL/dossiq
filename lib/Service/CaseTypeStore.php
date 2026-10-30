<?php

/**
 * Dossiq Case Type Store.
 *
 * Every OpenRegister read the case-type resolver performs.
 *
 * Split out of `CaseTypeResolver` for the reason `CaseStatusStore` was split
 * out of `StatusTransitionService`: the resolver keeps only the decision logic
 * — which ancestor a row came from, which declaration wins, whether a parent
 * closes a loop — while the mechanics of reaching the object store live here.
 * Resolving the bridge, reading the register and schema ids out of
 * configuration, and coercing whichever shape the store answered in are all
 * one concern, and they are not the concern the resolver is read for.
 *
 * Reads only. Nothing here writes, so a resolver bug cannot corrupt a case type.
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

use Throwable;

/**
 * OpenRegister reads for the case-type resolver.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeStore {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Read one case type.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, mixed> The row, or an empty array when unreadable.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function readCaseType(string $caseTypeId): array {
		return $this->readRow(schemaKey: 'case_type_schema', id: $caseTypeId);
	}//end readCaseType()

	/**
	 * Read one row of a configured schema by id.
	 *
	 * The one catch for single-row reads, as {@see self::search()} is for
	 * lists: an unreachable store or a missing row answers the empty array.
	 *
	 * @param string $schemaKey The settings key naming the schema.
	 * @param string $id        The row's id.
	 *
	 * @return array<string, mixed> The row, or an empty array.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function readRow(string $schemaKey, string $id): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);

		if ($objectService === null || $register === '' || $schema === '' || $id === '') {
			return [];
		}

		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return [];
		}

		return $this->asRow(value: $found);
	}//end readRow()

	/**
	 * Rows of one schema belonging to one case type.
	 *
	 * @param string $schemaKey  The settings key naming the schema.
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function rowsOfType(string $schemaKey, string $caseTypeId): array {
		if ($caseTypeId === '') {
			return [];
		}

		return $this->search(schemaKey: $schemaKey, filterKey: 'caseType', filterValue: $caseTypeId);
	}//end rowsOfType()

	/**
	 * The case type's workflow template: the one it names, else its active one.
	 *
	 * Here rather than on a caller because two of them ask: publishing writes
	 * the template out, and the publication checks walk its moves to find what
	 * nothing can reach. Both asked {@see self::rowsOfType()} and picked the
	 * same row the same way, and two readings of "which template is active" is
	 * how a publish and its own validation come to read different workflows.
	 *
	 * A type with templates but none marked active answers the first one, which
	 * is what an unmarked set has always meant here.
	 *
	 * `$named` is the `workflowDefinition` of the case type being judged. A
	 * check made while a case type is SAVED passes the incoming payload's value:
	 * an upgraded instance can hold an older active flow (a basis template
	 * written before the app shipped one), and judging by that refused the
	 * shipped case type on every upgrade (Zuiddrecht, 14 Oct). A name that does
	 * not resolve to a stored template falls back to the active one.
	 *
	 * @param string            $caseTypeId CaseType UUID.
	 * @param string|array|null $named      The flow the case type names: a uuid, an expanded row, or null.
	 *
	 * @return array<string, mixed> The template, or an empty array when there is none.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function activeTemplate(string $caseTypeId, string|array|null $named=null): array {
		$namedId = $this->referenceId(value: $named);
		if ($namedId !== '') {
			$template = $this->readRow(schemaKey: 'workflow_template_schema', id: $namedId);
			if ($template !== []) {
				return $template;
			}
		}

		$rows = $this->rowsOfType(schemaKey: 'workflow_template_schema', caseTypeId: $caseTypeId);

		$fallback = [];
		foreach ($rows as $row) {
			if (($row['isActive'] ?? false) === true) {
				return $row;
			}

			if ($fallback === []) {
				$fallback = $row;
			}
		}

		return $fallback;
	}//end activeTemplate()

	/**
	 * Every case type sharing one identifier: the version chain.
	 *
	 * ZGW's `identificatie` is what makes two rows versions of one zaaktype, so
	 * the chain is a FILTER and not a walk over `previousVersion`. The walk
	 * would answer the same list on sound data and a shorter one on a chain
	 * with a hole in it, and the shorter answer is the dangerous one: a version
	 * missing from the chain reads as a version that does not exist.
	 * `previousVersion` stays what it is, the audit link REQ-ZV-02 reads.
	 *
	 * The filter key is BARE. OpenRegister's objects search reads `identifier`
	 * as a filter and would read `filter[identifier]` as the empty set, which
	 * presents as a case type with no versions at all rather than as an error.
	 *
	 * @param string $identifier The shared identifier.
	 *
	 * @return array<int, array<string, mixed>> The versions, unordered.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function versionsWithIdentifier(string $identifier): array {
		$identifier = trim($identifier);
		if ($identifier === '') {
			return [];
		}

		return $this->search(
			schemaKey: 'case_type_schema',
			filterKey: 'identifier',
			filterValue: $identifier
		);
	}//end versionsWithIdentifier()

	/**
	 * Every case type the register holds.
	 *
	 * The catalogue rather than one chain, for the one act that crosses case
	 * types: a rebind picks a target from all of them, and asking per
	 * identifier would need the list of identifiers first.
	 *
	 * The filter is EMPTY on purpose, which is not the same as `IS NULL`: the
	 * bare-key grammar `search()` speaks narrows on what it is given, so giving
	 * it nothing is the whole catalogue. Drafts are answered too and are
	 * dropped by the caller, because "every case type" and "every case type you
	 * may put a running case on" are different questions and this one is the
	 * store's.
	 *
	 * `$scope` is OpenRegister's scope-lifting parameters (`_rbac`, `_multitenancy`),
	 * passed only by the reader that must not depend on the caller and that
	 * then answers every row whatever the caller may read. That reader is
	 * the handling team rules on the case schema ({@see \OCA\Dossiq\Service\Access\CaseTeamAccessProjector}).
	 * Projected from the rows one administrator happens to see, a save by a
	 * narrower account would quietly drop the teams of every case type it could
	 * not read. The limit is wider for the same reason: a case type missing from
	 * the page is a case type whose team loses its cases.
	 *
	 * @param array<string, bool> $scope The scope-lifting parameters, or none.
	 *
	 * @return array<int, array<string, mixed>> The case types, unordered.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function everyCaseType(array $scope=[]): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_type_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		$query = [
			'@self' => ['register' => $register, 'schema' => $schema],
			'_limit' => 200,
		];
		if ($scope !== []) {
			$query['_limit'] = 5000;
		}

		try {
			$found = $objectService->searchObjects($query, ...$scope);
		} catch (Throwable $e) {
			return [];
		}

		return $this->asRows(value: $found);
	}//end everyCaseType()

	/**
	 * Rows of one schema that belong to no case type at all.
	 *
	 * @param string $schemaKey The settings key naming the schema.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/property-definition-management/spec.md
	 */
	public function sharedRows(string $schemaKey): array {
		return $this->search(schemaKey: $schemaKey, filterKey: 'caseType', filterValue: 'IS NULL');
	}//end sharedRows()

	/**
	 * An object's id, whichever shape the store answered in.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or the empty string.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function rowId(array $row): string {
		$id = ($row['id'] ?? ($row['uuid'] ?? ''));
		if ($id === '' && isset($row['@self']) === true && is_array($row['@self']) === true) {
			$id = ($row['@self']['id'] ?? '');
		}

		return (string)$id;
	}//end rowId()

	/**
	 * The id a reference field points at.
	 *
	 * A `$ref` reaches PHP as a uuid string on a plain read and as an expanded
	 * object when the caller asked for it; reading only the string shape is how
	 * an inheritance chain silently stops one level up.
	 *
	 * @param mixed $value The reference value.
	 *
	 * @return string The id, or the empty string.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			return $this->rowId(row: $value);
		}

		return trim((string)$value);
	}//end referenceId()

	/**
	 * Search one configured schema, narrowed on one bare filter key.
	 *
	 * Filtered SERVER-side. Fetching everything and filtering here would drop
	 * whatever the first page did not contain, which is a case type quietly
	 * missing the statuses that happened to sort last.
	 *
	 * 🔑 THE FILTER KEY IS A PARAMETER SO THIS CLASS KEEPS ONE CATCH. Every
	 * read here answers the empty list when the store cannot be reached, and a
	 * second copy of that catch beside it is a second place for the classing to
	 * drift. `versionsWithIdentifier()` needed `identifier` rather than the
	 * `caseType` back-reference, and generalising the key was cheaper than
	 * another swallowing catch, which the architecture ratchet refuses anyway.
	 *
	 * The key is BARE. OpenRegister's objects search reads `identifier` as a
	 * filter and would read `filter[identifier]` as the empty set, which
	 * presents as a case type with no versions rather than as an error.
	 *
	 * @param string $schemaKey   The settings key naming the schema.
	 * @param string $filterKey   The property to narrow on.
	 * @param string $filterValue The value, or `IS NULL` for rows carrying none.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function search(string $schemaKey, string $filterKey, string $filterValue): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);

		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$found = $objectService->searchObjects(
				[
					'@self' => ['register' => $register, 'schema' => $schema],
					$filterKey => $filterValue,
					'_limit' => 200,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		return $this->asRows(value: $found);
	}//end search()

	/**
	 * Normalise one store answer into a plain row.
	 *
	 * Public because it is the app's ONE answer to "what shape did the store
	 * just hand me". OpenRegister answers an object with `jsonSerialize()` on
	 * some reads and a plain array on others, and every reader that keeps its
	 * own version of this eventually keeps a slightly different one.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function asRow(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end asRow()

	/**
	 * Normalise a store search answer into plain rows.
	 *
	 * The store answers with either a bare list or a paged envelope; reading
	 * one shape only is how a reader finds nothing on an instance that answers
	 * the other way.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function asRows(mixed $value): array {
		if (is_array($value) === true && isset($value['results']) === true) {
			$value = $value['results'];
		}

		if (is_array($value) === false) {
			return [];
		}

		$rows = [];
		foreach ($value as $row) {
			$row = $this->asRow(value: $row);
			if ($row !== []) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end asRows()
}//end class
