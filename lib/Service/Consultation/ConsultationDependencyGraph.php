<?php

/**
 * Dossiq consultation dependency graph.
 *
 * Guards the `dependsOn` edges between consultations against cycles. A cycle
 * would make a chain of adviesaanvragen unsatisfiable — each waiting on the
 * next, none ever becoming actionable — so the edge is refused before it is
 * written rather than detected later at the blocked case.
 *
 * Split out of ConsultationService because graph traversal is a different kind
 * of reasoning from the Awb lifecycle rules that service owns, and because the
 * traversal needs only one thing from the rest of the system: the ability to
 * read a consultation's dependency list.
 *
 * The walk is depth-first with a visited set carried by reference, so a node
 * reachable by several paths is expanded once and the traversal terminates on
 * any finite graph — including one that already contains a cycle not involving
 * the node under test.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Consultation
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
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Consultation;

/**
 * Detects cycles in the consultation `dependsOn` graph.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 */
class ConsultationDependencyGraph {
	/**
	 * Constructor.
	 *
	 * @param ConsultationRepository $repository Consultation reads (dependency lists)
	 */
	public function __construct(
		private readonly ConsultationRepository $repository,
	) {
	}//end __construct()

	/**
	 * Validate that adding the given dependsOn list would not create a dependency cycle.
	 *
	 * Uses depth-first traversal to detect cycles. Returns true if a cycle is
	 * detected, false if the dependency graph remains acyclic.
	 *
	 * @param string $consultationId The consultation being updated
	 * @param string[] $dependsOn The proposed dependency IDs
	 *
	 * @return bool True if a cycle would be created
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function wouldCreateCycle(string $consultationId, array $dependsOn): bool {
		// Quick self-reference check.
		if (in_array($consultationId, $dependsOn, true) === true) {
			return true;
		}

		$visited = [];
		foreach ($dependsOn as $depId) {
			if ($this->hasCycleDfs(
				startId: $consultationId,
				currentId: $depId,
				visited: $visited,
			) === true
			) {
				return true;
			}
		}

		return false;
	}//end wouldCreateCycle()

	/**
	 * Depth-first search helper for cycle detection in dependency graph.
	 *
	 * @param string $startId The original consultation ID (cycle target)
	 * @param string $currentId The current node being visited
	 * @param string[] $visited Already-visited node IDs (prevents re-traversal)
	 *
	 * @return bool True if startId is reachable from currentId (cycle detected)
	 */
	private function hasCycleDfs(string $startId, string $currentId, array &$visited): bool {
		if ($currentId === $startId) {
			return true;
		}

		if (in_array($currentId, $visited, true) === true) {
			return false;
		}

		$visited[] = $currentId;

		$consultation = $this->repository->getConsultation(consultationId: $currentId);
		if ($consultation === null) {
			return false;
		}

		$deps = $consultation['dependsOn'] ?? [];
		if (is_array($deps) === false) {
			return false;
		}

		foreach ($deps as $depId) {
			if ($this->hasCycleDfs(startId: $startId, currentId: $depId, visited: $visited) === true) {
				return true;
			}
		}

		return false;
	}//end hasCycleDfs()
}//end class
