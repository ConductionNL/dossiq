<?php

/**
 * Dossiq Workflow Lifecycle Guard.
 *
 * The preconditions a workflowTemplate must satisfy before its lifecycle
 * state may change. Split out of WorkflowDefinitionService so that service
 * keeps only the transitions themselves — write the row, deprecate the
 * predecessor, pin the caseType — while the rules that decide whether a
 * transition is allowed at all live here:
 *
 *   - what a row's authoritative lifecycle status actually is, including the
 *     legacy isDraft/isActive fallback for rows created before the enum;
 *   - that only a draft may be published, and only when every status it
 *     references belongs to its own caseType (referential integrity);
 *   - that only a published row may be deprecated, and never the last
 *     published version of a caseType that still has cases pinned to it.
 *
 * Every refusal is logged with its reason; the caller only learns "no".
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
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
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

use OCA\Dossiq\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a workflow definition may be published or deprecated.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class WorkflowLifecycleGuard {

	/**
	 * Lifecycle states. Mirrors the enum on the workflowTemplate schema.
	 */
	public const STATUS_DRAFT = 'draft';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_DEPRECATED = 'deprecated';

	/**
	 * Constructor.
	 *
	 * @param WorkflowDefinitionRepository $repository The definition repository.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly WorkflowDefinitionRepository $repository,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the authoritative lifecycle status of a row.
	 *
	 * Prefers the new lifecycleStatus field; falls back to the legacy isDraft
	 * + isActive booleans for objects created before the schema bump.
	 *
	 * @param array<string, mixed> $row Definition row.
	 *
	 * @return string One of draft|published|deprecated.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function statusOf(array $row): string {
		$status = (string)($row['lifecycleStatus'] ?? '');
		if ($status === self::STATUS_DRAFT
			|| $status === self::STATUS_PUBLISHED
			|| $status === self::STATUS_DEPRECATED
		) {
			return $status;
		}

		// Legacy fallback.
		$isDraft = (bool)($row['isDraft'] ?? true);
		$isActive = (bool)($row['isActive'] ?? false);

		if ($isDraft === true) {
			return self::STATUS_DRAFT;
		}

		if ($isActive === true) {
			return self::STATUS_PUBLISHED;
		}

		return self::STATUS_DEPRECATED;
	}//end statusOf()

	/**
	 * Assert a row may be published: it MUST be a draft, carry a caseType
	 * reference, and only reference statuses owned by that caseType.
	 *
	 * @param array<string, mixed> $current The definition row to check.
	 * @param array<int, mixed> $transitions The row's decoded transitions.
	 * @param string $id The definition UUID (for logging).
	 *
	 * @return bool True when the row may be published.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function isPublishableDraft(array $current, array $transitions, string $id): bool {
		if ($this->statusOf(row: $current) !== self::STATUS_DRAFT) {
			$this->logger->warning(
				'Dossiq: publish() — definition is not a draft',
				['app' => Application::APP_ID, 'id' => $id]
			);
			return false;
		}

		$caseTypeId = (string)($current['caseType'] ?? '');
		$foreign = $this->transitionsReferenceForeignStatuses(
			caseTypeId: $caseTypeId,
			transitions: $transitions
		);
		if ($caseTypeId === '' || $foreign === true) {
			$this->logger->warning(
				'Dossiq: publish() — referential integrity failure',
				['app' => Application::APP_ID, 'id' => $id]
			);
			return false;
		}

		return true;
	}//end isPublishableDraft()

	/**
	 * Assert a published row may be deprecated: it MUST be published, and
	 * MUST NOT be the last published version of a caseType that still has
	 * open cases. Logs the refusal reason.
	 *
	 * @param array<string, mixed> $current The definition row to check.
	 * @param string $id The definition UUID.
	 *
	 * @return bool True when the row may be deprecated.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function isDeprecatable(array $current, string $id): bool {
		if ($this->statusOf(row: $current) !== self::STATUS_PUBLISHED) {
			$this->logger->warning(
				'Dossiq: deprecate() — definition is not published',
				['app' => Application::APP_ID, 'id' => $id]
			);
			return false;
		}

		$caseTypeId = (string)($current['caseType'] ?? '');
		if ($caseTypeId !== '' && $this->isLastPublishedForCaseType(id: $id, caseTypeId: $caseTypeId) === true
			&& $this->repository->hasCasesFor(caseTypeId: $caseTypeId) === true
		) {
			$this->logger->warning(
				'Dossiq: deprecate() — last published definition with open cases',
				['app' => Application::APP_ID, 'id' => $id, 'caseType' => $caseTypeId]
			);
			return false;
		}

		return true;
	}//end isDeprecatable()

	/**
	 * Whether this id is the last published row for its caseType.
	 *
	 * @param string $id The current definition UUID.
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return bool True when no other published version exists.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	private function isLastPublishedForCaseType(string $id, string $caseTypeId): bool {
		$count = 0;
		foreach ($this->repository->listVersionsForCaseType(caseTypeId: $caseTypeId) as $row) {
			if ((string)($row['id'] ?? '') === $id) {
				continue;
			}

			if ($this->statusOf(row: $row) === self::STATUS_PUBLISHED) {
				$count++;
			}
		}

		return ($count === 0);
	}//end isLastPublishedForCaseType()

	/**
	 * Validate that every status referenced in transitions belongs to the
	 * linked caseType. Returns true when the references are *invalid*.
	 *
	 * @param string $caseTypeId The linked caseType UUID.
	 * @param array<int, mixed> $transitions The decoded transitions.
	 *
	 * @return bool True when a transition references a foreign status.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	private function transitionsReferenceForeignStatuses(string $caseTypeId, array $transitions): bool {
		if ($caseTypeId === '' || $transitions === []) {
			return false;
		}

		$statusIds = $this->repository->listStatusTypeIds(caseTypeId: $caseTypeId);
		if ($statusIds === []) {
			// No statusTypes yet — cannot validate. Treat as ok.
			return false;
		}

		foreach ($transitions as $transition) {
			if (is_array($transition) === false) {
				continue;
			}

			foreach (['fromStatus', 'toStatus'] as $key) {
				$ref = (string)($transition[$key] ?? '');
				if ($ref !== '' && in_array($ref, $statusIds, true) === false) {
					return true;
				}
			}
		}

		return false;
	}//end transitionsReferenceForeignStatuses()
}//end class
