/**
 * Who holds which right on a case, and where each grant came from.
 *
 * 🔴 EVERY LINE BELOW IS A READ. Nothing here decides who may open a case.
 *
 * D22 put the grant, its provenance, the deny and the inheritance in
 * OpenRegister, and the design note that follows from it (D-1) says a method in
 * dossiq that decides who may see a case is a finding. So this module fetches
 * five OpenRegister answers and hands them on in the shape they arrived in. It
 * does not merge a grant with a deny, does not fill a missing answer with a
 * default, and does not cache: a copy of an access decision is a second access
 * decision that nobody updates (D-5).
 *
 * The five reads, and why each one is here:
 *
 *   GET /apps/openregister/api/permissions
 *       The grantable verbs, each with the app that owns it and a sentence a
 *       person can read, plus the deny enforcement mode. Without it a right is
 *       a bare word like `update` in a panel meant for an auditor.
 *
 *   GET /apps/openregister/api/objects/{register}/{schema}/{id}/shares
 *       The grants written on THIS case. This is the share half of the
 *       requirement: a holder whose right came from a hand-off rather than
 *       from a role.
 *
 *   GET /apps/openregister/api/permissions/compare-roles?register=…
 *       Each role and the verbs it holds. This is the role half: the holder is
 *       the role, and the source is the register's role definition.
 *
 *   GET /apps/openregister/api/scopes?register=…&schema=…
 *       The caller's own effective answer per verb, with `provenance` beside
 *       it naming the rule that decided: the object block, the schema rule,
 *       the register default, the named role, or the deny that removed it.
 *       While the deny is staged it rides along as `stagedDeny`.
 *
 *   GET /apps/openregister/api/permissions/deny-preview?register=…&schema=…
 *       The deny rules as written, before anyone has hit one.
 *
 * WHY A FAILED READ IS `null` AND NEVER `[]`. An empty list is a legitimate
 * answer: a case with no share grants has none. A panel that turned "we could
 * not ask" into "nobody holds anything" would tell an auditor the opposite of
 * the truth. So a failed read answers null and the panel says the source could
 * not be read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The register cases live in. */
export const CASE_REGISTER = 'dossiq'

/** The schema a case is an object of. */
export const CASE_SCHEMA = 'case'

/**
 * GET one OpenRegister path, answering null when it cannot be read.
 *
 * @param {string} path   Path under the Nextcloud root, no leading slash.
 * @param {object} params Query parameters.
 *
 * @return {Promise<object|null>} The body, or null when the read failed.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
async function read(path, params = {}) {
	try {
		const response = await axios.get(generateUrl(`/${path}`), { params })
		// A non-JSON body arrives as a string, and a string has keys in
		// JavaScript, so `body.rules` on it is undefined rather than an error.
		// That would render as "no rules" instead of as a failed read.
		if (typeof response?.data !== 'object' || response.data === null) {
			return null
		}
		return response.data
	} catch {
		return null
	}
}

/**
 * The grantable verbs and the deny enforcement mode.
 *
 * @return {Promise<object|null>} `{permissions, denyEnforcement, rejectedDeclarations}`, or null.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export function fetchPermissionCatalogue() {
	return read('apps/openregister/api/permissions')
}

/**
 * The grants written on one case.
 *
 * @param {string} caseId The case uuid.
 *
 * @return {Promise<Array|null>} The grants, or null when they could not be read.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export async function fetchObjectGrants(caseId) {
	const body = await read(
		`apps/openregister/api/objects/${CASE_REGISTER}/${CASE_SCHEMA}/${caseId}/shares`,
	)
	if (body === null || !Array.isArray(body.results)) {
		return null
	}
	return body.results
}

/**
 * Each role in the case register and the verbs it holds.
 *
 * @return {Promise<object|null>} `{register, roles, exclusive, shared}`, or null.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export function fetchRoleGrants() {
	return read('apps/openregister/api/permissions/compare-roles', {
		register: CASE_REGISTER,
	})
}

/**
 * The caller's own effective verbs on the case schema, with their provenance.
 *
 * @return {Promise<object|null>} The scope entry for the case schema, or null.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export async function fetchCallerScope() {
	const body = await read('apps/openregister/api/scopes', {
		register: CASE_REGISTER,
		schema: CASE_SCHEMA,
	})
	if (body === null || !Array.isArray(body.scopes)) {
		return null
	}
	// The endpoint answers a matrix even when narrowed, so the case row is
	// picked by name rather than by position. Reading `scopes[0]` would name
	// whichever schema happened to sort first the day a second one matched.
	const scope = body.scopes.find((entry) => entry?.schema === CASE_SCHEMA)
	if (!scope) {
		return null
	}
	return { user: body.user, isAdmin: body.isAdmin, groups: body.groups, ...scope }
}

/**
 * The deny rules as written, for the case register and schema.
 *
 * @return {Promise<object|null>} `{denyEnforcement, enforcing, ruleCount, rules}`, or null.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export function fetchDenyRules() {
	return read('apps/openregister/api/permissions/deny-preview', {
		register: CASE_REGISTER,
		schema: CASE_SCHEMA,
	})
}

/**
 * Turn OpenRegister's four answers into one list of rows to render.
 *
 * 🔑 THIS IS A RENDERER, NOT AN EVALUATOR, and the difference is the whole
 * requirement. Each row restates ONE rule that OpenRegister reported, with the
 * holder it named and the source it came from. No row is the product of two
 * rules: a deny is its own row, never subtracted from a grant, because the
 * moment this function decided that a deny beats a grant it would be a second
 * evaluator, and the first time it disagreed with OpenRegister the difference
 * would be a disclosure.
 *
 * @param {object}      answers              The four reads.
 * @param {Array|null}  answers.objectGrants Grants written on this case.
 * @param {object|null} answers.roleGrants   Roles and the verbs they hold.
 * @param {object|null} answers.callerScope  The caller's own effective verbs.
 * @param {object|null} answers.denyRules    The deny rules as written.
 *
 * @return {Array<object>} Rows of `{holder, right, source, detail}`.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export function grantRows({ objectGrants, roleGrants, callerScope, denyRules }) {
	const rows = []

	for (const grant of objectGrants || []) {
		for (const right of grant?.actions || []) {
			rows.push({
				holder: grant.principal || grant.userId || grant.groupId || '',
				right,
				// A grant that names its own source keeps it. That is how an
				// inherited grant stays legible once OpenRegister's
				// `rbac-inherits-to-children` reports one: the grant on a case
				// type GROUP arrives here saying `group`, and this row says so
				// instead of calling every grant on the object a share.
				source: grant.source || grant.inheritedFrom || 'share',
				detail: grant.expires ? String(grant.expires) : '',
			})
		}
	}

	for (const role of roleGrants?.roles || []) {
		for (const right of role?.actions || []) {
			rows.push({
				holder: role.role || '',
				right,
				source: 'role',
				detail: '',
			})
		}
	}

	for (const rule of denyRules?.rules || []) {
		rows.push({
			holder: rule.principal || '',
			right: rule.action || '',
			source: denyRules.enforcing ? 'deny' : 'staged-deny',
			detail: rule.level ? `${rule.level}: ${rule.subject || ''}` : '',
		})
	}

	// The caller's own row is last and separate: it is the only entry that is
	// an ANSWER rather than a rule, and OpenRegister wrote both the verdict and
	// the source on it.
	for (const [right, record] of Object.entries(callerScope?.provenance || {})) {
		rows.push({
			holder: callerScope.user || '',
			right,
			source: record?.source || 'unknown',
			granted: record?.granted,
			detail: record?.role || record?.principal || '',
			stagedDeny: record?.stagedDeny || null,
		})
	}

	return rows
}
