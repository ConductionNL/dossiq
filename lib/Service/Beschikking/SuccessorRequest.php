<?php

/**
 * Dossiq SuccessorRequest.
 *
 * One request for a successor to a signed beschikking: the beschikking it
 * corrects or withdraws, whether it is a wijzigings- or an
 * intrekkingsbeschikking, what the handler wrote for it and the template it
 * is composed from. It also answers what can be said of the successor
 * without a store: whether its kind is one a successor may be, the fields it
 * is composed with, its case and its template.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\Exception\RefusedException;

/**
 * The successor BeschikkingService::issueSuccessor() issues.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class SuccessorRequest {

	/**
	 * The kinds a successor may be: a wijzigingsbeschikking or an intrekkingsbeschikking.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = ['amendment', 'withdrawal'];

	/**
	 * Constructor.
	 *
	 * @param string               $originalId The beschikking being corrected or withdrawn.
	 * @param string               $kind       `amendment` or `withdrawal`.
	 * @param array<string, mixed> $overrides  Content of the successor: rationale, decision, addressee.
	 * @param string|null          $templateId The template; the original's when null.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $originalId,
		public readonly string $kind,
		public readonly array $overrides,
		public readonly ?string $templateId,
	) {
	}//end __construct()

	/**
	 * Refuse a kind that is neither a wijzigings- nor an intrekkingsbeschikking.
	 *
	 * @return void
	 *
	 * @throws RefusedException 422 for an unknown kind.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function assertKnownKind(): void {
		if (in_array($this->kind, self::KINDS, true) === false) {
			throw new RefusedException(
				rule: 'successor-kind-unknown',
				sentence: 'A correction is either a wijzigingsbeschikking or an intrekkingsbeschikking.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertKnownKind()

	/**
	 * The fields the successor is composed with.
	 *
	 * The addressee carries over unless the correction names another: the
	 * person who received the original is who the correction is for.
	 *
	 * @param array<string, mixed> $original The stored original.
	 *
	 * @return array<string, mixed> The compose overrides.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function fieldsFrom(array $original): array {
		$fields = [
			'decisionType' => $this->kind,
			'supersedes' => $this->originalId,
			'addressee' => (array)($this->overrides['addressee'] ?? ($original['addressee'] ?? [])),
		];

		foreach (['rationale', 'decision'] as $field) {
			if (array_key_exists($field, $this->overrides) === true) {
				$fields[$field] = $this->overrides[$field];
			}
		}

		return $fields;
	}//end fieldsFrom()

	/**
	 * The case the original belongs to, which the successor belongs to as well.
	 *
	 * @param array<string, mixed> $original The stored original.
	 *
	 * @return string The case id.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function caseIdFrom(array $original): string {
		return (string)($original['caseId'] ?? '');
	}//end caseIdFrom()

	/**
	 * The template the successor is written from: the one the handler named,
	 * else the original's, else null to let compose choose.
	 *
	 * @param array<string, mixed> $original The stored original.
	 *
	 * @return string|null The template id.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function templateFrom(array $original): ?string {
		if ($this->templateId !== null) {
			return $this->templateId;
		}

		$templateId = trim((string)($original['templateId'] ?? ''));
		if ($templateId === '') {
			return null;
		}

		return $templateId;
	}//end templateFrom()
}//end class
