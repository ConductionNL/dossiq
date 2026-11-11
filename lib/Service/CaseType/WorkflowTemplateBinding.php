<?php

/**
 * The workflow template a case is bound to on its new case type
 *
 * A case that is rebound to another case type, or moved to another version of
 * its own, takes that type's active workflow template; without an active one
 * the first template listed; and with none it is left unbound. The version
 * comes from the template and defaults to 1.
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
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\CaseTypeStore;

/**
 * Binds a case to the workflow template of the case type it lands on.
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */
final class WorkflowTemplateBinding {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore $store Reads the target type's workflow templates.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
	) {
	}//end __construct()

	/**
	 * The case bound to the target type's workflow template, or unbound when it has none.
	 *
	 * @param array<string, mixed> $case             The case being moved.
	 * @param string               $targetCaseTypeId The case type it lands on.
	 *
	 * @return array<string, mixed> The case with workflowTemplate and workflowVersion set.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function bind(array $case, string $targetCaseTypeId): array {
		$chosen = $this->chosen(
			rows: $this->store->rowsOfType(
				schemaKey: 'workflow_template_schema',
				caseTypeId: $targetCaseTypeId
			)
		);

		if ($chosen === []) {
			$case['workflowTemplate'] = null;
			$case['workflowVersion'] = null;

			return $case;
		}

		$case['workflowTemplate'] = $this->store->rowId(row: $chosen);
		$case['workflowVersion'] = (int)($chosen['version'] ?? 1);

		return $case;
	}//end bind()

	/**
	 * The active template, else the first one listed, else none.
	 *
	 * @param array<int, array<string, mixed>> $rows The templates.
	 *
	 * @return array<string, mixed> The chosen template, or an empty array.
	 */
	private function chosen(array $rows): array {
		$chosen = [];
		foreach ($rows as $row) {
			if (($row['isActive'] ?? false) === true) {
				return $row;
			}

			if ($chosen === []) {
				$chosen = $row;
			}
		}

		return $chosen;
	}//end chosen()
}//end class
