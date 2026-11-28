<?php

/**
 * Contract of ProvisionAssignedGroups
 *
 * The public surface of ProvisionAssignedGroups that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of ProvisionAssignedGroups.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProvisionAssignedGroupsInterface {

	/**
	 * Every group the shipped register data assigns steps to.
	 *
	 * This list is the provisioning counterpart of the literals in
	 * lib/Settings/dossiq_register.json and lib/Settings/register.d/*.json;
	 * ProvisionAssignedGroupsTest sweeps the shipped flows and fails when a
	 * group is assigned there that this list does not provision, so the two
	 * cannot drift apart silently. The sweep reads `assigneeFallback` as well
	 * as `assignee`: a fallback nobody is a member of fails exactly as loudly
	 * as a primary nobody is a member of, and it fails on the case the primary
	 * was already unable to serve.
	 *
	 * `bezwaarcommissie` is the bezwaaradviescommissie's own group, kept
	 * separate from `behandelaars` on purpose: Awb art. 7:13 requires the
	 * advisory committee to be independent of the officials who handled the
	 * case, so routing its two steps to the handlers would be worse than the
	 * unassigned steps it replaces.
	 *
	 * 🔴 THE SECOND BLOCK IS GATES, NOT ASSIGNMENTS, and it was missing.
	 * `AccessControlGroupsAreProvisionedTest` sweeps the group ids the CODE
	 * gates access on, and measured 2026-09-05 that seven of the nine were
	 * created by nothing at all. `IGroupManager::isInGroup()` cannot tell a
	 * missing group from an empty one — both answer false, with no log line —
	 * so each of those was a permanent silent denial for every non-admin, and
	 * the feature behind it read as broken rather than restricted: process
	 * mining, the AI audit export, the KCC citizen lookup, and the free-form
	 * status transition.
	 *
	 * `procest-admin` keeps the pre-rename spelling deliberately; see the
	 * FROZEN note on `TransitionAuthorizer::ADMIN_GROUP_ID`. Provisioning it is
	 * not renaming it.
	 *
	 * Creating a group here never disturbs one an organisation already has:
	 * `run()` skips any id that exists and seeds only the ones it just made, so
	 * an instance with its own `beheerders` keeps that group and its members
	 * untouched.
	 *
	 * @var array<int, string>
	 */
	public const ASSIGNED_GROUPS = [
		// Assigned work by the shipped flows.
		'behandelaars',
		'bezwaarcommissie',
		// Gated on by the access checks in lib/.
		'procest-admin',
		'beheerders',
		'auditors',
		'secretariaat',
		'controllers',
		'kcc',
		'klantcontact',
		// The employee complaint alert audience (EmployeeAlertAudience).
		'hr-coordinators',
		'department-heads',
		// Assigned a notification role by the case schema's
		// `authorization.roles` (register.d/66-notification-routing.json).
		// `behandelaars` above already carries the behandelaar role. A role
		// assigned a group the server does not have is not a quiet team: the
		// resolver reports it and the dispatcher records `recipient-unresolved`
		// on every firing, so an unprovisioned group here is a fault row per
		// case rather than a missing notice. AuthorizationRolesAreProvisioned
		// Test sweeps the shipped roles against this list.
		'coordinatoren',
		// Named by a field rule rather than by a flow step: a case type's
		// `fieldRoleRules` hands these two the fields it takes off the
		// handlers, and OpenRegister's property RBAC reads the membership.
		// An authorization group the server does not have fails the other way
		// round from an assignment group: nobody qualifies for the allow list,
		// so the field is withheld from everyone who is not an administrator,
		// and the person who lost it has nothing to look at. `dossiq-risk-
		// assessment` (register.d/38-markers-and-assessments.json) has been in
		// that state since it shipped and is provisioned here for the same
		// reason.
		'dossiq-coordinators',
		'dossiq-quality',
		'dossiq-risk-assessment',
		// The extra permission on a BSN and on the special-category fields of
		// the sociaal-domein schemas (sensitive-fields-declared, row 5.6). Same
		// direction of failure as the three above: an authorization group the
		// server does not have matches nobody, so the field is withheld from
		// every reader who is not an administrator. The group is created EMPTY
		// on purpose. Sensitive data should be readable by the people an
		// administrator names, not by everybody who happened to be installed.
		'dossiq-sensitive',
		// The staff group the register level authorization grants
		// (register.d/91-register-access.json, case-access-by-handling-team).
		// A register closed to a group the server does not have is closed to
		// everybody but administrators, so it is provisioned with the rest.
		// Who else belongs in it is SeedStaffGroup's, once, on the upgrade.
		'dossiq-medewerkers',
		// The role groups of the admin data (register.d/92-admin-data-access.json):
		// the privacy officers read and handle data breaches, the social-domain
		// staff read and write indications and case meetings, and handle the
		// re-integration case type's cases. Seeded with the administrators like the others; an
		// administrator adds the officers and staff.
		'dossiq-privacy',
		'dossiq-sociaal-domein',
	];
}//end interface
