<?php

/**
 * Procest Guard Evaluator interface.
 *
 * Implementations evaluate a single guard configuration against a case for
 * a given user, returning a deterministic GuardResult. Guards MUST be
 * side-effect-free: they only read state.
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transitions;

/**
 * Strategy interface for guard evaluation.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T04
 */
interface GuardEvaluatorInterface {
	/**
	 * Evaluate the guard.
	 *
	 * @param array<string, mixed> $guardConfig The guard configuration block from the workflowTemplate transition
	 * @param array<string, mixed> $case The case object as an array
	 * @param string $userId The current user UID
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult;
}//end interface
