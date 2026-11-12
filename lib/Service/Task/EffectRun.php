<?php

/**
 * Dossiq EffectRun
 *
 * One declared effect of a completed task, with the case and context it runs on.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Task
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
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

/**
 * The effect, its trimmed type, the case and the dispatch context.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
final class EffectRun {

	/**
	 * The effect's type, trimmed.
	 *
	 * @var string
	 */
	public readonly string $type;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $effect  The declared effect.
	 * @param array<string, mixed> $case    The case the task is on.
	 * @param array<string, mixed> $context What ran it, for the handlers.
	 */
	public function __construct(
		public readonly array $effect,
		public readonly array $case,
		public readonly array $context,
	) {
		$this->type = trim((string)($effect['type'] ?? ''));
	}//end __construct()
}//end class
