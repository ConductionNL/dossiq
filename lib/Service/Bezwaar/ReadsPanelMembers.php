<?php

/**
 * Dossiq panel member reader.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
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

namespace OCA\Dossiq\Service\Bezwaar;

/**
 * Reads a stored panel as member UIDs: trimmed, without blanks.
 *
 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
 */
trait ReadsPanelMembers {

	/**
	 * The panel's member UIDs, trimmed, without blanks.
	 *
	 * @param array<mixed> $panel The panel as stored.
	 *
	 * @return list<string> The members.
	 *
	 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
	 */
	private function panelMembers(array $panel): array {
		$members = [];
		foreach ($panel as $member) {
			if (is_scalar($member) === true && trim((string)$member) !== '') {
				$members[] = trim((string)$member);
			}
		}

		return $members;
	}//end panelMembers()
}//end trait
