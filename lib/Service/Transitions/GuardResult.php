<?php

/**
 * Procest Guard Result value object.
 *
 * Carries the outcome of a single guard evaluation: pass flag, optional
 * failure message, and structured details (e.g. `silent: true` for role
 * guards that should hide a transition entirely).
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
 * Immutable value object returned by every GuardEvaluator.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T04
 */
final class GuardResult {
	/**
	 * Constructor.
	 *
	 * @param bool $passed Whether the guard passed
	 * @param string|null $failureMessage Optional user-facing failure message
	 * @param array<string, mixed> $details Structured guard details (e.g. silent role hide)
	 */
	public function __construct(
		public readonly bool $passed,
		public readonly ?string $failureMessage = null,
		public readonly array $details = [],
	) {
	}//end __construct()
}//end class
