<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Exception\RefusedException;

/**
 * A declared move on a loaded case: the ask, the case as read and the transition it takes.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class TransitionStep {
	/**
	 * Constructor.
	 *
	 * @param TransitionAsk        $ask        The move as asked.
	 * @param array<string, mixed> $caseData   The case.
	 * @param array<string, mixed> $transition The transition definition.
	 */
	public function __construct(
		public readonly TransitionAsk $ask,
		public readonly array $caseData,
		public readonly array $transition,
	) {
	}//end __construct()

	/**
	 * The same move on another copy of the case, such as the one just saved.
	 *
	 * @param array<string, mixed> $caseData The case.
	 *
	 * @return self The move.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function withCaseData(array $caseData): self {
		return new self(ask: $this->ask, caseData: $caseData, transition: $this->transition);
	}//end withCaseData()

	/**
	 * The case's status: the statusType UUID the move starts from.
	 *
	 * @return string The status, or ''.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function currentId(): string {
		return (string)($this->caseData['status'] ?? '');
	}//end currentId()

	/**
	 * The status the transition enters.
	 *
	 * @return string The status, or ''.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function toStatus(): string {
		return (string)($this->transition['toStatus'] ?? '');
	}//end toStatus()

	/**
	 * The case's version as read, for the optimistic lock.
	 *
	 * @return int The `@self.version`, else the top-level version, else 0.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function readVersion(): int {
		return (int)(($this->caseData['@self']['version'] ?? ($this->caseData['version'] ?? 0)));
	}//end readVersion()

	/**
	 * What the status record of this move says.
	 *
	 * @param string|null                      $comment         The free-form comment.
	 * @param array<int, array<string, mixed>> $evaluatedGuards The guard evaluation results.
	 *
	 * @return array<string, mixed> The move as CaseMoveLedger records and dispatches it.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function move(?string $comment, array $evaluatedGuards): array {
		return [
			'fromStatus' => $this->currentId(),
			'toStatus' => $this->toStatus(),
			'label' => (string)($this->transition['label'] ?? ''),
			'comment' => $comment,
			'actor' => $this->ask->userId,
			'evaluatedGuards' => $evaluatedGuards,
			'noWorkflowTemplate' => false,
		];
	}//end move()

	/**
	 * Refuse a move that does not start from the status the case is in.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the transition names another from-status.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function assertStartsFromCurrent(): void {
		$fromStatus = (string)($this->transition['fromStatus'] ?? '');
		if ($fromStatus !== '' && $fromStatus !== $this->currentId()) {
			throw new RefusedException(
				rule: 'transition-from-status-mismatch',
				sentence: 'This move does not start from the status the case is in.',
				status: RefusedException::STATUS_REFUSED,
			);
		}
	}//end assertStartsFromCurrent()

	/**
	 * Refuse a move by a user outside the groups it is open to.
	 *
	 * @param bool $authorized Whether the acting user is in one of those groups.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the user is not.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function assertGroupAuthorized(bool $authorized): void {
		if ($authorized === false) {
			throw new RefusedException(
				rule: 'transition-unauthorized',
				sentence: 'You are not in a group this move is open to.',
				status: RefusedException::STATUS_FORBIDDEN,
			);
		}
	}//end assertGroupAuthorized()

	/**
	 * Refuse a move whose server-side guard evaluation failed.
	 *
	 * @param array<int, array<string, mixed>> $failed The failed guards, in evaluation order.
	 *
	 * @return void
	 *
	 * @throws GuardFailedException When any guard failed.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function assertGuardsPassed(array $failed): void {
		if (count($failed) > 0) {
			throw new GuardFailedException(failedGuards: $failed);
		}
	}//end assertGuardsPassed()
}//end class
