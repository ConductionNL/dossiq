// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What each role dossiq provisions may do, as the admin settings show it
 * (REQ-CRS-14). The roles and their acts come from the `roleActs` initial
 * state (lib/Service/Access/RoleActs.php); this file words them.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md#requirement-each-application-role-states-what-its-members-may-do-req-crs-14
 */

/**
 * Every act a role can allow, in words. RoleActsTest fails when an act in
 * RoleActs::ACTS has no entry here.
 *
 * @param {(app: string, text: string) => string} t The app's translate.
 * @return {Record<string, string>} Act => its description.
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md#requirement-each-application-role-states-what-its-members-may-do-req-crs-14
 */
export function roleActLabels(t) {
	return {
		'handle-assigned-cases': t('dossiq', 'Handle the cases and steps assigned to them'),
		'advise-on-objections': t('dossiq', 'Give the advisory committee\'s advice on an objection'),
		'make-any-transition': t('dossiq', 'Make any status transition, whichever group it is authorised for'),
		'handle-data-breaches': t('dossiq', 'Read and handle data breaches'),
		'handle-social-domain-records': t('dossiq', 'Read and write indications and case meetings'),
		'export-ai-audit-log': t('dossiq', 'Read and export the AI audit log'),
		'read-process-analytics': t('dossiq', 'Read process analytics'),
		'look-up-citizens': t('dossiq', 'Look up citizens in the base registry'),
		'read-reports': t('dossiq', 'Read the management reports'),
		'receive-employee-complaint-alerts': t('dossiq', 'Receive the alerts about complaints against employees'),
		'receive-coordinator-notices': t('dossiq', 'Receive the case notices addressed to coordinators'),
		'edit-coordinator-fields': t('dossiq', 'Read and edit the fields a case type gives coordinators'),
		'move-case-to-other-case-type': t('dossiq', 'Move a case to another case type version'),
		'supervise-reviews': t('dossiq', 'Supervise document reviews'),
		'edit-quality-fields': t('dossiq', 'Read and edit the fields a case type gives quality staff'),
		'read-risk-assessments': t('dossiq', 'Read risk assessments'),
		'read-sensitive-fields': t('dossiq', 'Read a BSN and the special-category fields'),
		'work-in-register': t('dossiq', 'Read and write the records of the dossiq register'),
		'review-documents': t('dossiq', 'Be asked to review a document'),
		'handle-reintegration-cases': t('dossiq', 'Handle the re-integration case type\'s cases'),
	}
}

/**
 * The rows of the roles card: each role with its acts in words.
 *
 * @param {(app: string, text: string) => string} t The app's translate.
 * @param {Array<{group: string, acts: Array<string>}>} table The `roleActs` initial state.
 * @return {Array<{group: string, acts: Array<string>}>} The rows; an unknown act shows its key.
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md#requirement-each-application-role-states-what-its-members-may-do-req-crs-14
 */
export function roleActRows(t, table) {
	const labels = roleActLabels(t)
	return (Array.isArray(table) ? table : [])
		.filter((row) => row && typeof row.group === 'string' && row.group !== '')
		.map((row) => ({
			group: row.group,
			acts: (Array.isArray(row.acts) ? row.acts : []).map((act) => labels[act] || act),
		}))
}
