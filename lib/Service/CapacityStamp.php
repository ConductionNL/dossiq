<?php

/**
 * One action that may have been taken in somebody else's name
 *
 * The case, who acted, what they did and whose work the case is: what
 * SubstitutionAuditService::stampIfSubstituted() reads once and its steps
 * share.
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
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one capacity stamp; it never leaves the service that built it.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
final class CapacityStamp {

	/**
	 * The case id.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * Who acted.
	 *
	 * @var string
	 */
	public string $actorId = '';

	/**
	 * What they did.
	 *
	 * @var string
	 */
	public string $action = '';

	/**
	 * Whose work the case is: its assignee.
	 *
	 * @var string
	 */
	public string $absentee = '';
}//end class
