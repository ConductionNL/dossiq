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

namespace OCA\Dossiq\Service\Transitions;

/**
 * A declared move as asked: which case, which transition, by whom, and the result a closing move carries.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class TransitionAsk {
	/**
	 * Constructor.
	 *
	 * @param string      $caseId       Case UUID.
	 * @param string      $transitionId The transition's id.
	 * @param string      $userId       The acting user's UID.
	 * @param string|null $resultTypeId The result a closing transition carries.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $transitionId,
		public readonly string $userId,
		public readonly ?string $resultTypeId,
	) {
	}//end __construct()
}//end class
