<?php

/**
 * The always-available acts a case type declares.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;

/**
 * Normalises the {@see AlwaysAvailableActs::DECLARATION} list on a case type.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
final class AlwaysAvailableActDeclaration {

	/**
	 * The acts a case type declares, normalised and in declared order.
	 *
	 * An act with no id names nothing a surface can invoke and is dropped: an
	 * unidentifiable button is one that fails when pressed.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return array<int, array<string, mixed>> The declared acts.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	public function actsOn(array $caseType): array {
		$acts = [];
		foreach ((new WorkflowJsonProperty())->decodeList(raw: ($caseType[AlwaysAvailableActs::DECLARATION] ?? [])) as $act) {
			if (is_array($act) === false) {
				continue;
			}

			$id = trim((string)($act['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$acts[] = [
				'id' => $id,
				'label' => trim((string)($act['label'] ?? $id)),
				'description' => trim((string)($act['description'] ?? '')),
				'effect' => trim((string)($act['effect'] ?? '')),
				'requiredRole' => trim((string)($act['requiredRole'] ?? '')),
			];
		}

		return $acts;
	}//end actsOn()
}//end class
