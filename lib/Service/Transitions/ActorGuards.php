<?php

/**
 * Dossiq ActorGuards.
 *
 * The guard evaluators that read who acts: the role and the delegated authority.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * The guard evaluators that read who acts: the role and the delegated authority.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class ActorGuards {
	/**
	 * Constructor.
	 *
	 * @param RoleGuard               $roleGuard Built-in role evaluator.
	 * @param DelegatedAuthorityGuard $authority Asks decidiq whether the acting person holds the authority.
	 */
	public function __construct(
		public readonly RoleGuard $roleGuard,
		public readonly DelegatedAuthorityGuard $authority,
	) {
	}//end __construct()
}//end class
