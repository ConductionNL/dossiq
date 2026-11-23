<?php

/**
 * Strips the links to a case that is being deleted from every case that holds one.
 *
 * Both halves are read: the cases this case's own relation list names, and
 * the cases that declared a link towards it (`/used`). Each counterpart is
 * written only when a relation or typed link to the deleted case went.
 * {@see \OCA\Dossiq\Service\CaseRelationService} adds, removes and
 * normalises the links themselves.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

/**
 * The deleted-case cleanup of the case relations.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
final class DeletedCaseLinks {

	/**
	 * Constructor.
	 *
	 * @param CaseRelationStore $store Reads the cases and writes the stripped ones.
	 * @param CaseRelationCodec $codec Reads and edits a case's relations and typed links.
	 */
	public function __construct(
		private readonly CaseRelationStore $store,
		private readonly CaseRelationCodec $codec,
	) {
	}//end __construct()

	/**
	 * Remove every counterpart entry pointing at a case that is being deleted.
	 *
	 * Invoked from the case-deletion path (next to the deelzaak orphan cleanup)
	 * so no dangling references survive. Scans every case whose `relatedCases`
	 * references the deleted UUID and strips those entries.
	 *
	 * @param string $caseId UUID of the case being deleted.
	 *
	 * @return int Number of counterpart cases updated.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function strip(string $caseId): int {
		if ($caseId === '') {
			return 0;
		}

		$updated = array_filter(
			$this->counterpartIdsOf(caseId: $caseId),
			fn (string $counterpartId): bool => $this->stripCounterpart(counterpartId: $counterpartId, caseId: $caseId)
		);

		return count($updated);
	}//end strip()

	/**
	 * Strip every link to a deleted case from one counterpart.
	 *
	 * @param string $counterpartId The counterpart.
	 * @param string $caseId        The case being deleted.
	 *
	 * @return bool True when the counterpart was written.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function stripCounterpart(string $counterpartId, string $caseId): bool {
		$counterpart = $this->store->fetchCase(caseUuid: $counterpartId);
		if ($counterpart === null) {
			return false;
		}

		return $this->stripLinksTo(counterpart: $counterpart, caseId: $caseId);
	}//end stripCounterpart()

	/**
	 * Remove every relation and typed link to a case from a counterpart, saving only when one went.
	 *
	 * @param array<string, mixed> $counterpart The counterpart.
	 * @param string               $caseId      The case being deleted.
	 *
	 * @return bool True when the counterpart was written.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function stripLinksTo(array $counterpart, string $caseId): bool {
		$relations = $this->codec->decode(case: $counterpart);
		$stripped  = $this->codec->removeAllForCase(relations: $relations, caseId: $caseId);
		$links     = $this->codec->typedLinks(case: $counterpart);
		$unlinked  = $this->codec->withoutTypedLink(links: $links, natureRelationship: null, targetId: $caseId);
		if ([count($stripped), $unlinked] === [count($relations), $links]) {
			return false;
		}

		$this->store->persistRelations(case: $counterpart, relations: $stripped, typedLinks: $unlinked);

		return true;
	}//end stripLinksTo()

	/**
	 * Every case that has to be touched when one case is deleted.
	 *
	 * Two sources, and both are needed. This case's own list names what IT
	 * declared. `/used` names the cases that declared a link TOWARDS it, which
	 * its own list no longer mentions now that the counterpart write is gone.
	 * The mirror used to make that half complete by accident; without the
	 * reverse read a link declared from the far side would survive the case it
	 * points at.
	 *
	 * @param string $caseId The case being deleted.
	 *
	 * @return array<int, string> The counterpart uuids, de-duplicated.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function counterpartIdsOf(string $caseId): array {
		$ids = array_merge(
			$this->declaredCounterpartIds(caseId: $caseId),
			$this->incomingCounterpartIds(caseId: $caseId)
		);

		return array_values(
			array_unique(
				array_filter(
					$ids,
					static fn (string $ref): bool => ($ref !== '' && $ref !== $caseId)
				)
			)
		);
	}//end counterpartIdsOf()

	/**
	 * The cases a case's own relation list names.
	 *
	 * @param string $caseId The case being deleted.
	 *
	 * @return array<int, string> The ids, possibly empty or repeated.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function declaredCounterpartIds(string $caseId): array {
		// Even when the case is already gone we still scan counterparts: the
		// relation entries on OTHER cases are what must be cleaned up.
		$deleted = $this->store->fetchCase(caseUuid: $caseId);
		if ($deleted === null) {
			return [];
		}

		$ids = [];
		foreach ($this->codec->decode(case: $deleted) as $relation) {
			$ids[] = (string)($relation['caseId'] ?? '');
		}

		return $ids;
	}//end declaredCounterpartIds()

	/**
	 * The cases that declared a link towards a case, read from `/used`.
	 *
	 * @param string $caseId The case being deleted.
	 *
	 * @return array<int, string> The ids, possibly empty or repeated.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function incomingCounterpartIds(string $caseId): array {
		$ids = [];
		foreach ($this->store->relationRows(caseUuid: $caseId, incoming: true) as $row) {
			$ids[] = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		}

		return $ids;
	}//end incomingCounterpartIds()
}//end class
