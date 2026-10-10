<?php

/**
 * Dossiq Beschikking Succession.
 *
 * Issues a correction or a withdrawal of a signed beschikking as a new,
 * numbered beschikking that points at the one it replaces (REQ-BES-012).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\StateMachineService;

/**
 * A correction is a numbered successor, never an edit.
 *
 * The successor is composed through {@see BeschikkingService::compose()}, so
 * it is born a draft with its OWN new number (decision 167) and walks the
 * whole lifecycle again: mandaat, signature, dispatch, and a bezwaartermijn
 * of its own. The original is touched once, to name its successor, and that
 * pointer is the only field the freeze lets anyone write on it
 * ({@see StateMachineService::WRITE_ONCE_FIELDS}).
 *
 * The successor is written BEFORE the pointer. If the pointer write fails,
 * a draft successor exists whose `supersedes` names the original, which is
 * visible and can be retried; the other order would leave a signed decision
 * claiming a successor that does not exist.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BeschikkingSuccession {

	/**
	 * The kinds a successor may be: a wijzigingsbeschikking or an intrekkingsbeschikking.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = ['amendment', 'withdrawal'];

	/**
	 * Constructor.
	 *
	 * @param BeschikkingService    $decisions    Composes the successor, numbering included.
	 * @param BeschikkingRepository $repository   Reads the original and writes its pointer.
	 * @param StateMachineService   $stateMachine Says whether the original is frozen.
	 */
	public function __construct(
		private readonly BeschikkingService $decisions,
		private readonly BeschikkingRepository $repository,
		private readonly StateMachineService $stateMachine,
	) {
	}//end __construct()

	/**
	 * Issue a successor to a signed beschikking.
	 *
	 * @param string               $originalId The beschikking being corrected or withdrawn.
	 * @param string               $kind       `amendment` or `withdrawal`.
	 * @param array<string, mixed> $overrides  Content of the successor: rationale, decision, addressee.
	 * @param string|null          $templateId The template; the original's when null.
	 *
	 * @return array<string, mixed> The successor, a draft with its own number.
	 *
	 * @throws RefusedException 422 for an unknown kind; 409 when the original is
	 *                          still a draft or has already been replaced.
	 * @throws \RuntimeException 'not_found' when the original does not exist.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function issue(string $originalId, string $kind, array $overrides = [], ?string $templateId = null): array {
		if (in_array($kind, self::KINDS, true) === false) {
			throw new RefusedException(
				rule: 'successor-kind-unknown',
				sentence: 'A correction is either a wijzigingsbeschikking or an intrekkingsbeschikking.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$original = $this->repository->requireBeschikking(decisionId: $originalId);

		if ($this->stateMachine->isImmutable(status: (string)($original['currentStatus'] ?? '')) === false) {
			throw new RefusedException(
				rule: 'successor-of-a-draft',
				sentence: 'This beschikking has not been signed yet. Change the draft instead of issuing a correction.',
			);
		}

		$this->refuseASecondSuccessor(original: $original);

		$successor = $this->decisions->compose(
			$this->caseIdOf(original: $original),
			($templateId ?? $this->templateOf(original: $original)),
			$this->successorFields(original: $original, kind: $kind, overrides: $overrides, originalId: $originalId),
		);

		$original['id'] = $originalId;
		$original['supersededBy'] = (string)($successor['id'] ?? '');
		$this->repository->save(decision: $original);

		return $successor;
	}//end issue()

	/**
	 * Refuse when the original already names a successor, and name that one.
	 *
	 * @param array<string, mixed> $original The stored original.
	 *
	 * @return void
	 *
	 * @throws RefusedException 409 naming the successor to correct instead.
	 */
	private function refuseASecondSuccessor(array $original): void {
		$successorId = trim((string)($original['supersededBy'] ?? ''));
		if ($successorId === '') {
			return;
		}

		$successor = $this->repository->find(decisionId: $successorId);
		$reference = trim((string)($successor['reference'] ?? ''));
		if ($reference === '') {
			$reference = $successorId;
		}

		throw new RefusedException(
			rule: 'already-superseded',
			sentence: 'This beschikking has already been replaced by '.$reference.'. Correct that beschikking instead.',
		);
	}//end refuseASecondSuccessor()

	/**
	 * The fields the successor is composed with.
	 *
	 * The addressee carries over unless the correction names another: the
	 * person who received the original is who the correction is for.
	 *
	 * @param array<string, mixed> $original   The stored original.
	 * @param string               $kind       The successor kind.
	 * @param array<string, mixed> $overrides  What the handler wrote.
	 * @param string               $originalId The original's id.
	 *
	 * @return array<string, mixed> The compose overrides.
	 */
	private function successorFields(array $original, string $kind, array $overrides, string $originalId): array {
		$fields = [
			'decisionType' => $kind,
			'supersedes' => $originalId,
			'addressee' => (array)($overrides['addressee'] ?? ($original['addressee'] ?? [])),
		];

		foreach (['rationale', 'decision'] as $field) {
			if (array_key_exists($field, $overrides) === true) {
				$fields[$field] = $overrides[$field];
			}
		}

		return $fields;
	}//end successorFields()

	/**
	 * The case the original belongs to, which the successor belongs to as well.
	 *
	 * @param array<string, mixed> $original The stored original.
	 *
	 * @return string The case id.
	 */
	private function caseIdOf(array $original): string {
		return (string)($original['caseId'] ?? '');
	}//end caseIdOf()

	/**
	 * The template the original was written from, or null to let compose choose.
	 *
	 * @param array<string, mixed> $original The stored original.
	 *
	 * @return string|null The template id.
	 */
	private function templateOf(array $original): ?string {
		$templateId = trim((string)($original['templateId'] ?? ''));
		if ($templateId === '') {
			return null;
		}

		return $templateId;
	}//end templateOf()
}//end class
