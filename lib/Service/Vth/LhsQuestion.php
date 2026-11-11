<?php

/**
 * What an inspector asks the LHS for: the case, the three axes and the inspection.
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
 * One LHS question: severity, behaviour and actor type, on a case.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md
 */
final class LhsQuestion {
	/**
	 * Constructor.
	 *
	 * @param string      $caseId     The parent case UUID.
	 * @param string      $severity   Severity axis value.
	 * @param string      $behaviour  Behaviour axis value.
	 * @param string      $actorType  Actor-type axis value.
	 * @param string|null $inspection The inspection rapport UUID, or null.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $severity,
		public readonly string $behaviour,
		public readonly string $actorType,
		public readonly ?string $inspection,
	) {
	}//end __construct()

	/**
	 * The matrix cell key: `severity:behaviour:actorType`.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function cellKey(): string {
		return $this->severity . ':' . $this->behaviour . ':' . $this->actorType;
	}//end cellKey()
}//end class
