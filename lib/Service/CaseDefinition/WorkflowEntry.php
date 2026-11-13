<?php

/**
 * Dossiq workflow template entry.
 *
 * One workflow template a case definition package carries: its archive entry
 * name and its raw content.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * One workflow template read from a package archive.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class WorkflowEntry {

	/**
	 * Constructor.
	 *
	 * @param string       $name    The archive entry, for the message.
	 * @param string|false $content The raw entry, or false when unreadable.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string|false $content,
	) {
	}//end __construct()
}//end class
