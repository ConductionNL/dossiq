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

namespace OCA\Dossiq\Service\Workflow;

use OCA\Dossiq\Kernel\Contracts\WorkflowLifecycleGuardInterface;

/**
 * One case type in a workflow backfill: its row, its stored templates and,
 * once written, the template it is pinned to.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
final class BackfillCaseType {

	/**
	 * Constructor.
	 *
	 * @param string                           $id         The case type id.
	 * @param array<string, mixed>             $row        The case type.
	 * @param array<int, array<string, mixed>> $versions   Its stored workflow templates.
	 * @param string                           $templateId The template it is pinned to, or ''.
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $row,
		public readonly array $versions,
		public readonly string $templateId='',
	) {
	}//end __construct()

	/**
	 * Whether the case type is already pinned, or holds a template that is or was published.
	 *
	 * The admin sets the pin via the UI. A draft does not count: nobody is
	 * offered a draft, so a case type holding only drafts (a package may ship
	 * one for an administrator to finish) would have no flow a case can follow.
	 *
	 * @param WorkflowLifecycleGuardInterface $lifecycle Says which lifecycle a stored template is in.
	 *
	 * @return bool True when the backfill leaves it alone.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function hasAFlow(WorkflowLifecycleGuardInterface $lifecycle): bool {
		if ((string)($this->row['workflowDefinition'] ?? '') !== '') {
			return true;
		}

		$lifecycles = array_map(static fn (array $template): string => $lifecycle->statusOf(row: $template), $this->versions);

		return (array_diff($lifecycles, [WorkflowLifecycleGuard::STATUS_DRAFT]) !== []);
	}//end hasAFlow()

	/**
	 * The same case type, pinned to a template.
	 *
	 * @param string $templateId The template.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function pinnedTo(string $templateId): self {
		return new self(id: $this->id, row: $this->row, versions: $this->versions, templateId: $templateId);
	}//end pinnedTo()
}//end class
