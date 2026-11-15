<?php

/**
 * Dossiq RoleLinkScope.
 *
 * One declared relation role, read in the register the links of a case live in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-linking-a-case-with-a-role-writes-the-relation-and-the-record-req-lcr-2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

/**
 * The object service and register, and the role's definition.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-linking-a-case-with-a-role-writes-the-relation-and-the-record-req-lcr-2
 */
final class RoleLinkScope {

	/**
	 * Constructor.
	 *
	 * @param RoleLinkRegister   $location   The object service and register.
	 * @param RoleLinkDefinition $definition The role.
	 */
	public function __construct(
		public readonly RoleLinkRegister $location,
		public readonly RoleLinkDefinition $definition,
	) {
	}//end __construct()
}//end class
