<?php

/**
 * Where a case number is looked up, and the mailbox owner it is looked up for.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCP\IUser;

/**
 * Where a case number is looked up, and the mailbox owner it is looked up for.
 *
 * @spec openspec/specs/email-case-matching/spec.md
 */
final class CaseSearchScope {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The configured register.
	 * @param string $schema        The configured case schema.
	 * @param IUser  $owner         The mailbox owner.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly IUser $owner,
	) {
	}//end __construct()
}//end class
