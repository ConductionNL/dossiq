<?php

/**
 * The versions of one case type, as a list a page and an act can both read.
 *
 * A case type is never edited once cases run on it: a new version is a new
 * object carrying its own statuses, results and properties, and the rows are
 * tied together by the `identifier` they share. That makes the chain a fact
 * about the store rather than a fact about any one row, and it was a fact
 * nothing answered: the page showed one version and the API offered no way to
 * ask what the others were, so a handler looking at a case could not find out
 * which version had replaced theirs.
 *
 * This is the one reader of that chain. The Version chain panel on
 * `#CaseTypeDetail` asks OpenRegister directly, with the same filter and the
 * same sort, because a declared widget should not need an endpoint; this
 * answers the same question to the code that has to DECIDE on it, which is the
 * per-case move and the bulk variant of it.
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

use OCA\Dossiq\Service\CaseTypeStore;

/**
 * Reads a case type's version chain, newest version first.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */
class CaseTypeVersionChain {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore          $store    The app's one case type reader.
	 * @param DerivedCaseTypePayload $payloads What a version number means when the row carries none.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly DerivedCaseTypePayload $payloads,
	) {
	}//end __construct()

	/**
	 * Every version of the case type this id belongs to, newest first.
	 *
	 * A case type with no identifier is its own chain of one. That is not a
	 * degenerate case to guard against, it is what a type authored before the
	 * identifier was auto-generated looks like, and answering the empty list
	 * for it would tell a handler their case runs on a version that does not
	 * exist.
	 *
	 * @param string $caseTypeId The case type to read the chain of.
	 *
	 * @return array<int, array<string, mixed>> The versions, newest first.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function versionsOf(string $caseTypeId): array {
		$caseType = $this->store->readCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return [];
		}

		$rows = $this->store->versionsWithIdentifier(
			identifier: (string)($caseType['identifier'] ?? '')
		);

		$versions = [];
		$seen = [];
		foreach (array_merge($rows, [$caseType]) as $row) {
			$id = $this->store->rowId(row: $row);
			if ($id === '' || isset($seen[$id]) === true) {
				continue;
			}

			$seen[$id] = true;
			$versions[] = $this->entry(row: $row, id: $id, currentId: $caseTypeId);
		}

		usort(
			$versions,
			static fn (array $left, array $right): int => ($right['version'] <=> $left['version'])
		);

		return $versions;
	}//end versionsOf()

	/**
	 * The version of this chain that new cases get, if there is one.
	 *
	 * The version in use is the published one nothing has superseded. Two rows
	 * answering that description is data the publish path cannot produce, and
	 * the newest wins rather than the first read, because the read order of a
	 * store query is not a decision anybody made.
	 *
	 * @param string $caseTypeId Any version of the chain.
	 *
	 * @return array<string, mixed> The current version's entry, or an empty array.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function currentOf(string $caseTypeId): array {
		foreach ($this->versionsOf(caseTypeId: $caseTypeId) as $entry) {
			if ($entry['isDraft'] === false && $entry['supersededBy'] === '') {
				return $entry;
			}
		}

		return [];
	}//end currentOf()

	/**
	 * The versions a running case on this one could be moved to.
	 *
	 * Every published version of the chain except the one it is already on. A
	 * draft is left out because it is not a version anything runs on yet, and
	 * an older published version is left IN: a case filed under a version
	 * published by mistake has to be able to go back.
	 *
	 * @param string $caseTypeId The version the case is on.
	 *
	 * @return array<int, array<string, mixed>> The candidate versions, newest first.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function targetsFor(string $caseTypeId): array {
		$targets = [];
		foreach ($this->versionsOf(caseTypeId: $caseTypeId) as $entry) {
			if ($entry['isDraft'] === true || $entry['id'] === $caseTypeId) {
				continue;
			}

			$targets[] = $entry;
		}

		return $targets;
	}//end targetsFor()

	/**
	 * One version, as the page and the dialog read it.
	 *
	 * @param array<string, mixed> $row       The case type row.
	 * @param string               $id        Its id.
	 * @param string               $currentId The version the reader started from.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(array $row, string $id, string $currentId): array {
		return [
			'id' => $id,
			'title' => (string)($row['title'] ?? ''),
			'identifier' => (string)($row['identifier'] ?? ''),
			'version' => $this->payloads->versionOf(caseType: $row),
			'isDraft' => (($row['isDraft'] ?? false) === true),
			'validFrom' => (string)($row['validFrom'] ?? ''),
			'validUntil' => (string)($row['validUntil'] ?? ''),
			'previousVersion' => $this->store->referenceId(value: ($row['previousVersion'] ?? '')),
			'supersededBy' => $this->store->referenceId(value: ($row['supersededBy'] ?? '')),
			'isSelf' => ($id === $currentId),
		];
	}//end entry()
}//end class
