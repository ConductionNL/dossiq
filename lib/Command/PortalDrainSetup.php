<?php

/**
 * What one portal-request drain reads, writes and submits with.
 *
 * Built once by `DrainIntakeObjectsCommand::execute()` and handed through its private steps:
 * OpenRegister's object service and form submit, the administrator the cases are opened as, the
 * register, and the case and source schemas.
 *
 * @category Command
 * @package  OCA\Dossiq\Command
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

namespace OCA\Dossiq\Command;

use OCP\IUser;

/**
 * What one portal-request drain reads, writes and submits with.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */
final class PortalDrainSetup {

	/**
	 * Constructor.
	 *
	 * @param object|null $objectService OpenRegister's object service.
	 * @param object|null $submitter     The FormSubmitService.
	 * @param IUser|null  $admin         The admin the cases are opened as.
	 * @param string      $register      The dossiq register.
	 * @param string      $caseSchema    The case schema.
	 * @param string      $sourceSchema  The portaalVerzoek schema.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?object $objectService,
		public readonly ?object $submitter,
		public readonly ?IUser $admin,
		public readonly string $register,
		public readonly string $caseSchema,
		public readonly string $sourceSchema,
	) {
	}//end __construct()
}//end class
