<?php

/**
 * The case whose portal messages are read, and where they are read from.
 *
 * Built by `PortalCaseMessages::forCase()` and handed through the steps that read the case and its
 * messages: OpenRegister's object service, the register and the case.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

/**
 * The case whose portal messages are read, and where they are read from.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PortalCaseScope {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register.
	 * @param string $caseId        The case.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $caseId,
	) {
	}//end __construct()
}//end class
