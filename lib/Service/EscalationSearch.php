<?php

/**
 * Where one escalation path is looked up
 *
 * The object service, the register, the mandate schema and the role
 * assignment schema: the state MandaatEscalatieService::resolveEscalatiePath()
 * reads once, so its steps take one argument.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The store of one escalation path search; it never leaves the service that built it.
 *
 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
 */
final class EscalationSearch {

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
	 * The mandate schema.
	 *
	 * @var string
	 */
	public string $mandateSchema = '';

	/**
	 * The role assignment schema.
	 *
	 * @var string
	 */
	public string $assignmentSchema = '';
}//end class
