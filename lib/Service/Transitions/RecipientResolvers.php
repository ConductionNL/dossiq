<?php

/**
 * How a template message finds its recipients: the assignee resolver, the role resolver and the user manager.
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
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\RoleResolverService;
use OCP\IUserManager;

/**
 * How a template message finds its recipients: the assignee resolver, the role resolver and the user manager.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
final class RecipientResolvers {
	/**
	 * Constructor.
	 *
	 * @param AssigneeResolver    $assignees The case's id.
	 * @param RoleResolverService $roles     The participants holding a role on the case.
	 * @param IUserManager        $users     A participant user's address.
	 */
	public function __construct(
		public readonly AssigneeResolver $assignees,
		public readonly RoleResolverService $roles,
		public readonly IUserManager $users,
	) {
	}//end __construct()
}//end class
