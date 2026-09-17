<?php

/**
 * What one version of a case type asks that another does not.
 *
 * A pure comparison, and separate from the act that applies it for a reason
 * the analyser noticed before a reader would: the move service had grown to
 * hold both the question and the answer, the reads and the writes, and it went
 * past the complexity threshold. The seam is real rather than convenient. This
 * class never writes and never refuses; it reads two versions and says how they
 * differ. {@see CaseVersionMove} decides what to do about it.
 *
 * THE MAPPING IS BY NAME, AND THAT IS NOT A SHORTCUT. A statusType carries no
 * identity that survives a version: {@see \OCA\Dossiq\Service\CaseTypeCopyService}
 * copies each row into a new object with a new uuid, so the only thing two
 * versions of one status share is what it is called. A version where the author
 * renamed or deleted the status a case is sitting in is therefore precisely the
 * case that cannot be mapped, and this reports it as unmapped rather than
 * guessing: landing a case in the target's first status because the mapping
 * failed is the kind of write nobody can read back afterwards.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;

/**
 * Compares two versions of a case type against one running case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */
class CaseVersionDiff {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore        $store    The app's one case type reader.
	 * @param CaseTypeResolver     $resolver The effective blueprint of a version.
	 * @param CaseTypeVersionChain $chain    The versions, for naming them.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly CaseTypeResolver $resolver,
		private readonly CaseTypeVersionChain $chain,
	) {
	}//end __construct()

	/**
	 * How the target version differs, for this case.
	 *
	 * @param array<string, mixed> $case     The case as it stands.
	 * @param string               $sourceId The version it is on.
	 * @param string               $targetId The version asked about.
	 *
	 * @return array<string, mixed> The differences, and whether the case can land.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function between(array $case, string $sourceId, string $targetId): array {
		$sourceStatuses = $this->statusesByName(caseTypeId: $sourceId);
		$targetStatuses = $this->statusesByName(caseTypeId: $targetId);
		$sourceFields = $this->fieldNames(caseTypeId: $sourceId);
		$targetFields = $this->fieldNames(caseTypeId: $targetId);

		$statusName = $this->currentStatusName(case: $case, statuses: $sourceStatuses);
		$mapped = ($targetStatuses[$this->key(name: $statusName)] ?? []);
		$removedFields = array_values(array_diff($sourceFields, $targetFields));

		return [
			'from' => $this->versionLabel(caseTypeId: $sourceId),
			'to' => $this->versionLabel(caseTypeId: $targetId),
			'status' => [
				'from' => $statusName,
				'to' => (string)($mapped['name'] ?? ''),
				'targetStatusId' => (string)($mapped['id'] ?? ''),
				'mapped' => ($mapped !== []),
			],
			'statuses' => [
				'added' => $this->namesOnlyIn(left: $targetStatuses, right: $sourceStatuses),
				'removed' => $this->namesOnlyIn(left: $sourceStatuses, right: $targetStatuses),
			],
			'fields' => [
				'added' => array_values(array_diff($targetFields, $sourceFields)),
				'removed' => $removedFields,
				'answered' => $this->answeredAmong(case: $case, names: $removedFields),
			],
			'refusals' => $this->refusalsFor(statusName: $statusName, mapped: $mapped),
			'canMove' => ($mapped !== []),
		];
	}//end between()

	/**
	 * The statuses of one version, keyed by their comparable name.
	 *
	 * @param string $caseTypeId The version.
	 *
	 * @return array<string, array{id: string, name: string}> The statuses.
	 */
	private function statusesByName(string $caseTypeId): array {
		$byName = [];
		foreach ($this->resolver->statusTypesFor(caseTypeId: $caseTypeId) as $status) {
			$name = trim((string)($status['name'] ?? ''));
			if ($name === '') {
				continue;
			}

			$byName[$this->key(name: $name)] = [
				'id' => $this->store->rowId(row: $status),
				'name' => $name,
			];
		}

		return $byName;
	}//end statusesByName()

	/**
	 * The property names one version declares.
	 *
	 * @param string $caseTypeId The version.
	 *
	 * @return array<int, string> The names.
	 */
	private function fieldNames(string $caseTypeId): array {
		$names = [];
		foreach ($this->resolver->propertyDefinitionsFor(caseTypeId: $caseTypeId) as $definition) {
			$name = trim((string)($definition['name'] ?? ''));
			if ($name !== '') {
				$names[] = $name;
			}
		}

		sort($names);

		return array_values(array_unique($names));
	}//end fieldNames()

	/**
	 * The name of the status the case is sitting in.
	 *
	 * @param array<string, mixed>                           $case     The case.
	 * @param array<string, array{id: string, name: string}> $statuses Its version's statuses.
	 *
	 * @return string The name, or the empty string when the case has no status.
	 */
	private function currentStatusName(array $case, array $statuses): string {
		$statusId = $this->store->referenceId(value: ($case['status'] ?? ''));
		if ($statusId === '') {
			return '';
		}

		foreach ($statuses as $status) {
			if ($status['id'] === $statusId) {
				return $status['name'];
			}
		}

		return '';
	}//end currentStatusName()

	/**
	 * The sentences that stand between this case and the target version.
	 *
	 * @param string                           $statusName The status the case is in.
	 * @param array{id?: string, name?: string} $mapped    The status it would land in.
	 *
	 * @return array<int, string> The refusals, empty when the move is possible.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	private function refusalsFor(string $statusName, array $mapped): array {
		if ($mapped !== []) {
			return [];
		}

		if ($statusName === '') {
			return ['This case is not in a status of its own case type, so there is nothing to map onto the other version.'];
		}

		return ['The other version has no status called "' . $statusName . '", so this case has nowhere to land.'];
	}//end refusalsFor()

	/**
	 * The names present on the left and not on the right.
	 *
	 * @param array<string, array{id: string, name: string}> $left  The statuses to read from.
	 * @param array<string, array{id: string, name: string}> $right The statuses to compare against.
	 *
	 * @return array<int, string> The names.
	 */
	private function namesOnlyIn(array $left, array $right): array {
		$names = [];
		foreach ($left as $key => $status) {
			if (isset($right[$key]) === false) {
				$names[] = $status['name'];
			}
		}

		sort($names);

		return $names;
	}//end namesOnlyIn()

	/**
	 * The names among these that the case actually carries an answer for.
	 *
	 * A field that disappears in the target version and was never filled in
	 * costs the handler nothing; one that holds an answer is the sentence the
	 * dialog has to show before anybody presses the button.
	 *
	 * @param array<string, mixed> $case  The case.
	 * @param array<int, string>   $names The field names being dropped.
	 *
	 * @return array<int, string> The ones with an answer on this case.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	private function answeredAmong(array $case, array $names): array {
		$properties = ($case['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		$answered = [];
		foreach ($names as $name) {
			$value = ($properties[$name] ?? null);
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$answered[] = $name;
		}

		return $answered;
	}//end answeredAmong()

	/**
	 * How a version is named in an answer.
	 *
	 * @param string $caseTypeId The version.
	 *
	 * @return array<string, mixed> Its id, title and version number.
	 */
	private function versionLabel(string $caseTypeId): array {
		foreach ($this->chain->versionsOf(caseTypeId: $caseTypeId) as $entry) {
			if ($entry['id'] === $caseTypeId) {
				return ['id' => $entry['id'], 'title' => $entry['title'], 'version' => $entry['version']];
			}
		}

		return ['id' => $caseTypeId, 'title' => '', 'version' => 1];
	}//end versionLabel()

	/**
	 * The comparable form of a status name.
	 *
	 * Case and surrounding whitespace are not a difference between two versions
	 * of one status; they are how the same status was typed twice.
	 *
	 * @param string $name The name.
	 *
	 * @return string The key.
	 */
	private function key(string $name): string {
		return mb_strtolower(trim($name));
	}//end key()
}//end class
