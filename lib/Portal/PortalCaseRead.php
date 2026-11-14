<?php

/**
 * The case one portal read is about, and where it is read from.
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
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

/**
 * One case a portal read is about: the object service, the register and the case.
 *
 * Built by {@see PortalCaseReads} once all three are known.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PortalCaseRead {

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The case, already proven to be the resident's.
	 *
	 * @var string
	 */
	public string $caseId = '';
}//end class
