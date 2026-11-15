<?php

/**
 * Which page of the AI audit log is read: its size and where it starts, both already clamped.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Ai
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
 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Ai;

/**
 * Which page of the AI audit log is read: its size and where it starts, both already clamped.
 *
 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.1
 */
final class AuditPageWindow {

	/**
	 * Constructor.
	 *
	 * @param int $limit  Page size, already clamped.
	 * @param int $offset Paging offset, already clamped.
	 *
	 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.1
	 */
	public function __construct(
		public readonly int $limit,
		public readonly int $offset,
	) {
	}//end __construct()
}//end class
