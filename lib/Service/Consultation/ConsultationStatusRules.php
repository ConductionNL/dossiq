<?php

/**
 * Dossiq consultation status rules.
 *
 * What an advice request may be: the statuses it can hold, the moves between
 * them, the advice types an answer can carry, whether a request is still
 * holding its case, and the obligation that mirrors a mandatory request.
 * Reads no store.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Consultation;

use OCA\Dossiq\Service\Obligations\ObligationService;
use RuntimeException;

/**
 * The status graph, the advice types, the blocking rule and the mirroring obligation of an advice request.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ConsultationStatusRules {

	/**
	 * Valid consultation statuses.
	 */
	private const VALID_STATUSES = [
		'open',
		'received',
		'in_handling',
		'advice_uitgebracht',
		'closed',
		'withdrawn',
	];

	/**
	 * Valid advice response types.
	 */
	private const VALID_RESPONSES = [
		'positive',
		'positief_with_terms',
		'negative',
		'non_from_application',
	];

	/**
	 * Allowed status transitions (from => [allowed-to, ...]).
	 */
	private const STATUS_TRANSITIONS = [
		'open' => ['received', 'withdrawn'],
		'received' => ['in_handling', 'withdrawn'],
		'in_handling' => ['advice_uitgebracht', 'withdrawn'],
		'advice_uitgebracht' => ['closed'],
		'closed' => [],
		'withdrawn' => [],
	];

	/**
	 * The statuses in which a request no longer holds its case.
	 */
	private const RELEASED_STATUSES = ['advice_uitgebracht', 'closed', 'withdrawn'];

	/**
	 * Refuse a status that is not one of the declared statuses.
	 *
	 * @param string $newStatus The status asked for.
	 *
	 * @return void
	 *
	 * @throws RuntimeException If the status is unknown.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function assertKnownStatus(string $newStatus): void {
		if (in_array($newStatus, self::VALID_STATUSES, true) === false) {
			throw new RuntimeException('Invalid status: ' . $newStatus);
		}
	}//end assertKnownStatus()

	/**
	 * Refuse a move the declared status graph does not allow.
	 *
	 * Only a recognised current status constrains the move; a consultation
	 * whose stored status is absent or unknown may be set to any valid status
	 * so the graph never wedges an object that predates it.
	 *
	 * @param string $current   The stored status, empty when absent.
	 * @param string $newStatus The status asked for.
	 *
	 * @return void
	 *
	 * @throws RuntimeException If the graph refuses the move.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function assertMove(string $current, string $newStatus): void {
		if (array_key_exists($current, self::STATUS_TRANSITIONS) === false) {
			return;
		}

		if (in_array($newStatus, self::STATUS_TRANSITIONS[$current], true) === false) {
			throw new RuntimeException('Invalid status transition: ' . $current . ' -> ' . $newStatus);
		}
	}//end assertMove()

	/**
	 * The advice type of a response, refused when it is not a declared type.
	 *
	 * @param array<string, mixed> $response Response data (advice, notes, terms).
	 *
	 * @return mixed The advice type.
	 *
	 * @throws RuntimeException If the advice type is unknown.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function adviceOf(array $response): mixed {
		$advies = $response['advice'] ?? '';
		if (in_array($advies, self::VALID_RESPONSES, true) === false) {
			throw new RuntimeException('Invalid advice type: ' . $advies);
		}

		return $advies;
	}//end adviceOf()

	/**
	 * Whether one consultation is holding its case: a mandatory request that
	 * has not been answered, closed or withdrawn.
	 *
	 * @param array<string, mixed> $consultation The consultation row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function blocks(array $consultation): bool {
		if (($consultation['mandatory'] ?? false) !== true) {
			return false;
		}

		$status = (string)($consultation['status'] ?? '');

		return in_array($status, self::RELEASED_STATUSES, true) === false;
	}//end blocks()

	/**
	 * The obligation that mirrors a mandatory advice request, or null for one
	 * that is not mandatory.
	 *
	 * The consultation stays the surface a handler works with; the obligation
	 * is what the transition engine reads.
	 *
	 * @param string               $consultationId The consultation just created.
	 * @param array<string, mixed> $data           The consultation payload.
	 *
	 * @return array<string, string>|null The obligation to place.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function mirroringObligation(string $consultationId, array $data): ?array {
		if (($data['mandatory'] ?? false) !== true) {
			return null;
		}

		return [
			'case' => (string)($data['parentCase'] ?? ''),
			'kind' => ObligationService::KIND_ADVICE,
			'title' => (string)($data['subject'] ?? 'Advice request'),
			'placedOn' => (string)($data['assignee'] ?? ''),
			'placedOnGroup' => (string)($data['adviceAuthority'] ?? ''),
			'dueAt' => substr((string)($data['latestResponseDate'] ?? ''), 0, 10),
			'source' => $consultationId,
		];
	}//end mirroringObligation()

}//end class
