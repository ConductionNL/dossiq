<?php

/**
 * An override an inspector or manager asks for on a stored LHS recommendation.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Vth
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
 * @spec openspec/specs/enforcement-lhs/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Vth;

/**
 * The chosen intervention, its justification and the role the caller claims.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md
 */
final class LhsOverride {
	/**
	 * Constructor.
	 *
	 * @param string $intervention  The chosen intervention.
	 * @param string $justification The justification.
	 * @param string $userRole      The caller's role: inspector or manager.
	 */
	public function __construct(
		public readonly string $intervention,
		public readonly string $justification,
		public readonly string $userRole,
	) {
	}//end __construct()

	/**
	 * The authority the override is recorded under: manager only for the manager role.
	 *
	 * @return string manager or inspector.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function authority(): string {
		if ($this->userRole === 'manager') {
			return 'manager';
		}

		return 'inspector';
	}//end authority()
}//end class
