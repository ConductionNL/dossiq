<?php

/**
 * Dossiq role acts
 *
 * What the members of each group dossiq provisions may do, named by the act
 * and read from the gate that grants it. ProvisionAssignedGroups creates the
 * groups; this is the list the admin settings show beside each one, so an
 * administrator adding someone to `auditors` can read what that gives them.
 * A role that is not listed here, or an act not listed for a role, is not
 * granted by that role: the gates refuse it to a member who holds only that
 * role (OpenRegister's case authorization, TransitionAuthorizer and the
 * controllers' group checks).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md#requirement-each-application-role-states-what-its-members-may-do-req-crs-14
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

/**
 * The acts each provisioned group allows.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md#requirement-each-application-role-states-what-its-members-may-do-req-crs-14
 */
class RoleActs {

	/**
	 * Group id => the acts it allows, each named after its gate.
	 *
	 * The keys follow ProvisionAssignedGroups::ASSIGNED_GROUPS; RoleActsTest
	 * fails when a provisioned group has no acts here or a group here is not
	 * provisioned, and when an act has no label in the admin screen.
	 *
	 * @var array<string, list<string>>
	 */
	public const ACTS = [
		// The shipped flows assign steps to them; the case grants follow the assignee.
		'behandelaars' => ['handle-assigned-cases'],
		// BezwaarAdviceRequestController.
		'bezwaarcommissie' => ['advise-on-objections'],
		// TransitionAuthorizer::ADMIN_GROUP_ID passes every transition's group list.
		'procest-admin' => ['make-any-transition'],
		// RolePermissions, AiAuditExportController, ProcessMiningController, CitizenLookupGuard, ReportingAudience.
		'beheerders' => [
			'handle-data-breaches',
			'handle-social-domain-records',
			'export-ai-audit-log',
			'read-process-analytics',
			'look-up-citizens',
			'read-reports',
		],
		// AiAuditExportController.
		'auditors' => ['export-ai-audit-log'],
		'secretariaat' => ['export-ai-audit-log'],
		// ProcessMiningController, ReportingAudience.
		'controllers' => ['read-process-analytics', 'read-reports'],
		// CitizenLookupGuard.
		'kcc' => ['look-up-citizens'],
		'klantcontact' => ['look-up-citizens'],
		// EmployeeAlertAudience.
		'hr-coordinators' => ['receive-employee-complaint-alerts'],
		'department-heads' => ['receive-employee-complaint-alerts'],
		// The coordinator notification role (register.d/66-notification-routing.json).
		'coordinatoren' => ['receive-coordinator-notices'],
		// Field role rules, CaseRebindGate and CaseReviewerAccess.
		'dossiq-coordinators' => ['edit-coordinator-fields', 'move-case-to-other-case-type', 'supervise-reviews'],
		// Field role rules.
		'dossiq-quality' => ['edit-quality-fields'],
		// CaseRiskAssessmentService::READER_GROUP.
		'dossiq-risk-assessment' => ['read-risk-assessments'],
		// The sensitive fields' property authorization, HouseholdCasesController, SensitiveAnswerCheck.
		'dossiq-sensitive' => ['read-sensitive-fields'],
		// The dossiq register's authorization (register.d/91-register-access.json); review and recall requests.
		'dossiq-medewerkers' => ['work-in-register', 'review-documents'],
		// RolePermissions::PRIVACY_GROUP.
		'dossiq-privacy' => ['handle-data-breaches'],
		// RolePermissions::SOCIAL_DOMAIN_GROUP.
		'dossiq-sociaal-domein' => ['handle-social-domain-records', 'handle-reintegration-cases'],
	];

	/**
	 * Every role with its acts, in the order given.
	 *
	 * @param list<string> $groups The groups in display order; the provisioned ones by default.
	 *
	 * @return list<array{group: string, acts: list<string>}>
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md#requirement-each-application-role-states-what-its-members-may-do-req-crs-14
	 */
	public function table(array $groups=[]): array {
		if ($groups === []) {
			$groups = array_keys(self::ACTS);
		}

		$rows = [];
		foreach ($groups as $group) {
			$rows[] = ['group' => $group, 'acts' => (self::ACTS[$group] ?? [])];
		}

		return $rows;
	}//end table()

	/**
	 * Whether a role allows an act.
	 *
	 * @param string $group The group id.
	 * @param string $act   The act.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md#requirement-each-application-role-states-what-its-members-may-do-req-crs-14
	 */
	public function allows(string $group, string $act): bool {
		return in_array($act, (self::ACTS[$group] ?? []), true);
	}//end allows()
}//end class
