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
 * THE OBJECT NOW ANSWERS FOR ITSELF, AND THE FIVE READS STAY. openregister#3744
 * added `GET /api/objects/{r}/{s}/{id}/permissions`, which answers in one read
 * what the five below were assembled into, and a history endpoint beside it. An
 * openregister older than that answers 404 to both. So the new read is preferred
 * where it answers and the five are the fallback, because a panel that had
 * dropped them would render an empty access table on every older instance, and
 * an empty access table is the one answer an auditor must never be handed by
 * accident (D-6).
 *
 *   GET /apps/openregister/api/objects/{register}/{schema}/{id}/permissions
 *       Every principal holding a verb on THIS case, each with the rule behind
 *       it: the level the rule is written at, the role it arrived through, and
 *       whether the verb is in the published catalogue. The deny rules come
 *       back beside the grants, never subtracted from them.
 *
 *   GET /apps/openregister/api/objects/{register}/{schema}/{id}/permissions/history?at=
 *       The set as it stood at a moment, read from the object's audit trail,
 *       with who set it and which change took it away afterwards. This is the
 *       auditor's actual question: not what changed, but who could open this
 *       dossier in March.
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
 * GET one OpenRegister path, keeping the status beside the body.
 *
 * 🔑 THE STATUS IS PART OF THE ANSWER. A 403 on the access set is OpenRegister
 * answering: this reader may open the case and may not enumerate who else can,
 * which is a second right (openregister#3744). Folding that into "could not be
 * read" tells the reader the wrong thing twice, so the status travels with the
 * body and the caller decides (D-7).
 *
 * A transport failure has no status, and reports 0.
 *
 * @param {string} path   Path under the Nextcloud root, no leading slash.
 * @param {object} params Query parameters.
 *
 * @return {Promise<{status: number, data: object|null}>} The status and the body.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
async function readResult(path, params = {}) {
	try {
		const response = await axios.get(generateUrl(`/${path}`), { params })
		// A non-JSON body arrives as a string, and a string has keys in
		// JavaScript, so `body.rules` on it is undefined rather than an error.
		// That would render as "no rules" instead of as a failed read.
		if (typeof response?.data !== 'object' || response.data === null) {
			return { status: response?.status || 0, data: null }
		}
		return { status: response.status || 200, data: response.data }
	} catch (error) {
		return { status: error?.response?.status || 0, data: null }
	}
}

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
	const { data } = await readResult(path, params)
	return data
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
 * The permission set OpenRegister publishes for this case.
 *
 * 🔑 THE STATUS COMES BACK WITH IT, AND 403 IS NOT A FAILURE. OpenRegister
 * refuses this read to a caller who may open the case but holds no `manage` on
 * it, because enumerating the case workers on a dossier is a second right. That
 * refusal is an answer, and the panel says so rather than reporting that the
 * source could not be read (D-7).
 *
 * @param {string} caseId The case uuid.
 *
 * @return {Promise<{status: number, set: object|null}>} The status and the set.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export async function fetchObjectPermissions(caseId) {
	const { status, data } = await readResult(
		`apps/openregister/api/objects/${CASE_REGISTER}/${CASE_SCHEMA}/${caseId}/permissions`,
	)

	if (data === null || Array.isArray(data.holders) === false) {
		return { status, set: null }
	}

	return { status, set: data }
}

/**
 * The set as it stood at a moment, from the object's audit trail.
 *
 * @param {string} caseId The case uuid.
 * @param {string} at     An ISO-8601 moment to report the set as of.
 *
 * @return {Promise<{status: number, history: object|null}>} The status and the history.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export async function fetchAccessHistory(caseId, at) {
	const { status, data } = await readResult(
		`apps/openregister/api/objects/${CASE_REGISTER}/${CASE_SCHEMA}/${caseId}/permissions/history`,
		{ at },
	)

	if (data === null || Array.isArray(data.changes) === false) {
		return { status, history: null }
	}

	return { status, history: data }
}

/**
 * The end and the area written on one rule, as OpenRegister reported them.
 *
 * 🔴 NEITHER IS COMPARED TO ANYTHING. An `until` in the past is still rendered,
 * with its date, because whether an expired grant still answers is resolved in
 * OpenRegister on every path a question takes (openregister#3750). A clock here
 * would be a second one, and two clocks disagree first on the day the grant
 * runs out, which is the day somebody looks (D-8).
 *
 * A verb grant carries its rule as the entry itself. A role grant carries the
 * whole holder list, because the role is what grants and the holders are who
 * holds it, so the entry naming this principal is picked out of the list.
 *
 * @param {object|Array|string|null} rule      The rule as OpenRegister wrote it.
 * @param {string}                   principal The holder this row is about.
 *
 * @return {{until: string, scopedTo: object|null}} The end and the area.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export function constraintsOf(rule, principal) {
	const none = { until: '', scopedTo: null }
	if (rule === null || typeof rule !== 'object') {
		return none
	}

	let entry = rule
	if (Array.isArray(rule)) {
		entry = rule.find(
			(candidate) =>
				candidate === principal
				|| (candidate !== null
					&& typeof candidate === 'object'
					&& [
						candidate.principal,
						candidate.group,
						candidate.role,
						candidate.user,
					].includes(principal)),
		)
	}

	if (entry === null || typeof entry !== 'object' || Array.isArray(entry)) {
		return none
	}

	return {
		until: typeof entry.until === 'string' ? entry.until : '',
		scopedTo: entry.scopedTo || null,
	}
}

/**
 * One rule OpenRegister reported, as a row to render.
 *
 * @param {object}  rule      The rule.
 * @param {boolean} refusing  Whether this rule takes a right away.
 * @param {boolean} enforcing Whether a refusal is in force on this instance.
 *
 * @return {object} The row.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
function ruleRow(rule, refusing, enforcing) {
	const principal = rule?.principal || ''
	const { until, scopedTo } = constraintsOf(rule?.rule ?? null, principal)

	let source = rule?.level || 'object'
	if (refusing === true) {
		source = enforcing === true ? 'deny' : 'staged-deny'
	}

	return {
		holder: principal,
		right: rule?.action || '',
		source,
		detail: rule?.role || '',
		// Which case handed this rule down, when OpenRegister says one did
		// (row Q13.23). It is carried and never compared to anything: whether
		// an inherited grant still answers is resolved in OpenRegister, on
		// every path a question takes.
		inheritedFrom: rule?.inheritedFrom || '',
		level: rule?.level || '',
		role: rule?.role || '',
		declared: rule?.declared !== false,
		conditional: rule?.conditional === true,
		until,
		scopedTo,
	}
}

/**
 * Turn the object's own permission set into rows to render.
 *
 * 🔑 A DENY IS ITS OWN ROW. OpenRegister reports grants and refusals in two
 * lists, and they stay two lists here. Subtracting one from the other is the
 * second evaluator D-1 forbids, and the first time it disagreed with
 * OpenRegister the difference would be a disclosure.
 *
 * @param {object|null} set The set, as `fetchObjectPermissions` returned it.
 *
 * @return {Array<object>} The rows.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export function objectPermissionRows(set) {
	if (set === null || typeof set !== 'object') {
		return []
	}

	const enforcing = set.denyEnforcement === 'enforcing'

	const rows = []
	for (const holder of set.holders || []) {
		for (const rule of holder?.rules || []) {
			rows.push(ruleRow(rule, false, enforcing))
		}
	}

	for (const rule of set.denied || []) {
		rows.push(ruleRow(rule, true, enforcing))
	}

	return rows
}

/**
 * The set as it stood at the moment asked about, ready to render.
 *
 * WHY "UNANSWERED" IS ITS OWN STATE. OpenRegister answers `asOf: null` when its
 * trail does not reach back that far, or when nothing had been written by then.
 * Rendering that as an empty table would say nobody held anything at that
 * moment, which is a different claim and one this panel cannot make.
 *
 * @param {object|null} history The history, as `fetchAccessHistory` returned it.
 *
 * @return {{answered: boolean, at: string, rows: Array<object>, setBy: string, changedAfterwardsBy: object|null}}
 *         The set at that moment.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export function asOfRows(history) {
	const unanswered = {
		answered: false,
		at: history?.at || '',
		rows: [],
		setBy: '',
		changedAfterwardsBy: null,
	}

	const asOf = history?.asOf || null
	if (asOf === null || typeof asOf !== 'object') {
		return unanswered
	}

	const rows = []
	for (const holder of asOf.holders || []) {
		for (const rule of holder?.rules || []) {
			rows.push(ruleRow(rule, false, false))
		}
	}

	return {
		answered: true,
		at: asOf.at || history?.at || '',
		rows,
		setBy: asOf.setBy || '',
		changedAfterwardsBy: asOf.changedAfterwardsBy || null,
	}
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
 * THE OBJECT'S OWN ANSWER WINS WHERE IT ARRIVES. `objectPermissions` is one
 * read that already names the rule behind every grant, so when OpenRegister
 * answered it the share and role reads are not replayed on top: the same grant
 * would arrive twice, once with its rule and once without, and an auditor
 * counting holders would count it twice. The deny preview goes the same way:
 * that answer carries the refusals beside the grants already. The caller's own
 * verdict still rides along, because it is the one thing not in it: an answer
 * about this reader rather than a rule about everybody (D-6).
 *
 * @param {object}      answers                   The reads.
 * @param {object|null} answers.objectPermissions The object's own permission set.
 * @param {Array|null}  answers.objectGrants      Grants written on this case.
 * @param {object|null} answers.roleGrants        Roles and the verbs they hold.
 * @param {object|null} answers.callerScope       The caller's own effective verbs.
 * @param {object|null} answers.denyRules         The deny rules as written.
 *
 * @return {Array<object>} Rows of `{holder, right, source, detail}`.
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
export function grantRows({
	objectPermissions = null,
	objectGrants,
	roleGrants,
	callerScope,
	denyRules,
}) {
	const rows = []

	if (objectPermissions !== null) {
		rows.push(...objectPermissionRows(objectPermissions))
	}

	for (const grant of (objectPermissions === null ? objectGrants : null) || []) {
		for (const right of grant?.actions || []) {
			rows.push({
				holder: grant.principal || grant.userId || grant.groupId || '',
				right,
				// A grant that names its own source keeps it. That is how an
				// inherited grant stays legible once OpenRegister's
				// `rbac-inherits-to-children` reports one: the grant on a case
				// type GROUP arrives here saying `group`, and this row says so
				// instead of calling every grant on the object a share.
				//
				// 🔴 `inheritedFrom` IS NOT A SOURCE NAME, it is the id of the
				// case the grant came from, and it used to fall into `source`
				// whenever OpenRegister reported one without a `source`. That
				// put a uuid through `sourceLabel`, which returns its argument
				// unchanged for a key it does not know, so the Where it comes
				// from column read as a raw identifier where a sentence
				// belongs. The two are separated here: the source says what
				// kind of rule it is, the ancestor rides alongside and the
				// panel names it.
				source:
					grant.source || (grant.inheritedFrom ? 'inherited' : 'share'),
				inheritedFrom: grant.inheritedFrom || '',
				detail: grant.expires ? String(grant.expires) : '',
			})
		}
	}

	for (const role of (objectPermissions === null ? roleGrants?.roles : null)
		|| []) {
		for (const right of role?.actions || []) {
			rows.push({
				holder: role.role || '',
				right,
				source: 'role',
				detail: '',
			})
		}
	}

	for (const rule of (objectPermissions === null ? denyRules?.rules : null)
		|| []) {
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

/**
 * The schema a case type is an object of.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
export const CASE_TYPE_SCHEMA = 'caseType'

/**
 * The case, and the field rules its type declares per role.
 *
 * TWO READS AND NOT ONE, BECAUSE THEY ANSWER DIFFERENT QUESTIONS. The case
 * carries `@self.fieldRules`, which is what OpenRegister decided for THIS
 * reader in THIS state. The case type carries the declaration, which is the
 * rule behind that decision and the sentence its author wrote. A panel with
 * only the first can say a field is missing and nothing else; a panel with only
 * the second can say what the rules are and not whether any of them is why the
 * reader is looking at a gap.
 *
 * Neither is evaluated here. The decision is read, never recomputed: it is
 * resolved per user and per state on OpenRegister's render path, and a second
 * evaluator would eventually disagree with the one that actually withheld the
 * field.
 *
 * A failed read answers null, never an empty list, for the reason the whole
 * module gives: "we could not ask" and "there is no rule" are opposite answers.
 *
 * @param {string} caseId The case uuid.
 *
 * @return {Promise<{decided: object|null, declared: Array<object>|null}>} The answer.
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
export async function fetchFieldRoleRules(caseId) {
	if (!caseId) {
		return { decided: null, declared: null }
	}

	const caseObject = await read(
		`apps/openregister/api/objects/${CASE_REGISTER}/${CASE_SCHEMA}/${caseId}`,
	)
	if (caseObject === null) {
		return { decided: null, declared: null }
	}

	const decided = caseObject['@self']?.fieldRules ?? null
	const caseTypeId = referenceId(caseObject.caseType)
	if (!caseTypeId) {
		return { decided, declared: null }
	}

	const caseType = await read(
		`apps/openregister/api/objects/${CASE_REGISTER}/${CASE_TYPE_SCHEMA}/${caseTypeId}`,
	)
	if (caseType === null) {
		return { decided, declared: null }
	}

	return {
		decided,
		declared: Array.isArray(caseType.fieldRoleRules)
			? caseType.fieldRoleRules
			: [],
	}
}

/**
 * The id a reference carries, whichever shape it arrived in.
 *
 * A `$ref` reaches the browser as a uuid string on a plain read and as an
 * expanded object when somebody asked for it. Reading only the string shape is
 * how the second read silently never happens.
 *
 * @param {unknown} value The reference.
 *
 * @return {string} The id, or the empty string.
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
function referenceId(value) {
	if (value && typeof value === 'object') {
		return String(value['@self']?.id ?? value.id ?? value.uuid ?? '')
	}

	return String(value ?? '')
}
