<?php

/**
 * Where the cases a timeline entry hangs on are read: the object service, register and case schema.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timeline
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
 * @spec openspec/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timeline;

/**
 * Where the cases a timeline entry hangs on are read: the object service, register and case schema.
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */
final class TimelineCoordinates {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param mixed  $register      The register as configured.
	 * @param mixed  $schema        The case schema as configured.
	 *
	 * @spec openspec/specs/case-history-surface/spec.md
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly mixed $register,
		public readonly mixed $schema,
	) {
	}//end __construct()
}//end class
