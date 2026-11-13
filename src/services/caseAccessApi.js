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
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
 */
async function readResult(path, params = {}) {
	try {
		const response = await axios.get(generateUrl(`/${path}`), { params })
		return answeredResult(response)
	} catch (error) {
		return { status: error?.response?.status || 0, data: null }
	}
}

/**
 * An answered read as its status and body.
 *
 * A non-JSON body arrives as a string, and a string has keys in JavaScript,
 * so `body.rules` on it is undefined rather than an error. That would render
 * as "no rules" instead of as a failed read, so such a body reads as none.
 *
 * @param {object|undefined} response The response.
 * @return {{status: number, data: object|null}} The status and the body.
 */
function answeredResult(response) {
	if (isObjectBody(response?.data) === false) {
		return { status: response?.status || 0, data: null }
	}
	return { status: response.status || 200, data: response.data }
}

/**
 * Whether a response body is a JSON object (or list), not a string or null.
 *
 * @param {unknown} data The body.
 * @return {boolean} True for an object body.
 */
function isObjectBody(data) {
	return typeof data === 'object' && data !== null
}

/**
 * GET one OpenRegister path, answering null when it cannot be read.
 *
 * @param {string} path   Path under the Nextcloud root, no leading slash.
 * @param {object} params Query parameters.
 *
 * @return {Promise<object|null>} The body, or null when the read failed.
 * @spec openspec/specs/case-management/spec.md
 */
async function read(path, params = {}) {
	const { data } = await readResult(path, params)
	return data
}

/**
 * The grantable verbs and the deny enforcement mode.
 *
 * @return {Promise<object|null>} `{permissions, denyEnforcement, rejectedDeclarations}`, or null.
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
 */
export function constraintsOf(rule, principal) {
	const none = { until: '', scopedTo: null }
	if (!isObject(rule)) {
		return none
	}

	const entry = Array.isArray(rule) ? holderEntry(rule, principal) : rule
	if (!isObject(entry) || Array.isArray(entry)) {
		return none
	}

	return entryConstraints(entry)
}

/**
 * Whether a value is a non-null object (an array included).
 *
 * @param {unknown} value The value.
 * @return {boolean} True for an object.
 */
function isObject(value) {
	return value !== null && typeof value === 'object'
}

/**
 * The end date and area one grant entry carries.
 *
 * @param {object} entry The grant entry.
 * @return {{until: string, scopedTo: object|null}} The end and the area.
 */
function entryConstraints(entry) {
	return {
		until: typeof entry.until === 'string' ? entry.until : '',
		scopedTo: entry.scopedTo || null,
	}
}

/**
 * The entry of a role grant's holder list that names this principal.
 *
 * @param {Array} holders The holder list.
 * @param {string} principal The holder this row is about.
 * @return {object|string|undefined} The entry, undefined when none names them.
 */
function holderEntry(holders, principal) {
	return holders.find(
		(candidate) =>
			candidate === principal
			|| namesPrincipal(candidate, principal),
	)
}

/**
 * Whether a holder entry object names the principal as its principal,
 * group, role or user.
 *
 * @param {unknown} candidate One entry of the holder list.
 * @param {string} principal The holder this row is about.
 * @return {boolean} True when it names them.
 */
function namesPrincipal(candidate, principal) {
	return isObject(candidate)
		&& [
			candidate.principal,
			candidate.group,
			candidate.role,
			candidate.user,
		].includes(principal)
}

/**
 * One rule OpenRegister reported, as a row to render.
 *
 * @param {object}      rule    The rule.
 * @param {string|null} refusal For a rule that takes a right away, `deny`
 *                              when the refusal is in force on this
 *                              instance, else `staged-deny`; null for a grant.
 *
 * @return {object} The row.
 * @spec openspec/specs/case-management/spec.md
 */
function ruleRow(rule, refusal) {
	const principal = rule?.principal || ''
	const { until, scopedTo } = constraintsOf(rule?.rule ?? null, principal)

	return {
		holder: principal,
		right: rule?.action || '',
		source: ruleSource(rule, refusal),
		detail: rule?.role || '',
		...ruleOrigin(rule),
		until,
		scopedTo,
	}
}

/**
 * What a row says about its rule's source: the refusal for a rule that takes
 * a right away, else the level it was granted on (`object` when unnamed).
 *
 * @param {object|null} rule    The rule.
 * @param {string|null} refusal `deny`, `staged-deny`, or null for a grant.
 * @return {string} The source.
 */
function ruleSource(rule, refusal) {
	return refusal ?? (rule?.level || 'object')
}

/**
 * Where a rule comes from and how it was written.
 *
 * @param {object|null} rule The rule.
 * @return {{inheritedFrom: string, level: string, role: string, declared: boolean, conditional: boolean}} The fields.
 */
function ruleOrigin(rule) {
	return {
		// Which case handed this rule down, when OpenRegister says one did
		// (row Q13.23). It is carried and never compared to anything: whether
		// an inherited grant still answers is resolved in OpenRegister, on
		// every path a question takes.
		inheritedFrom: rule?.inheritedFrom || '',
		level: rule?.level || '',
		role: rule?.role || '',
		declared: rule?.declared !== false,
		conditional: rule?.conditional === true,
	}
}

/**
 * One row per rule of each holder, as grants.
 *
 * @param {Array|null|undefined} holders The holders and their rules.
 * @return {Array<object>} The rows.
 */
function heldRuleRows(holders) {
	const rows = []
	for (const holder of holders || []) {
		rows.push(...(holder?.rules || []).map((rule) => ruleRow(rule, null)))
	}
	return rows
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
 * @spec openspec/specs/case-management/spec.md
 */
export function objectPermissionRows(set) {
	if (set === null || typeof set !== 'object') {
		return []
	}

	const enforcing = set.denyEnforcement === 'enforcing'
	const refusal = enforcing ? 'deny' : 'staged-deny'

	return [
		...heldRuleRows(set.holders),
		...(set.denied || []).map((rule) => ruleRow(rule, refusal)),
	]
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
 * @spec openspec/specs/case-management/spec.md
 */
export function asOfRows(history) {
	const asOf = history?.asOf || null
	if (asOf === null || typeof asOf !== 'object') {
		return unansweredAsOf(history)
	}

	return answeredAsOf(asOf, history)
}

/**
 * The as-of answer when OpenRegister's trail reaches the moment.
 *
 * @param {object} asOf The set at that moment.
 * @param {object|null} history The history, for the moment when the answer names none.
 * @return {{answered: boolean, at: string, rows: Array<object>, setBy: string, changedAfterwardsBy: object|null}} The set at that moment.
 */
function answeredAsOf(asOf, history) {
	return {
		answered: true,
		at: asOf.at || history?.at || '',
		rows: heldRuleRows(asOf.holders),
		setBy: asOf.setBy || '',
		changedAfterwardsBy: asOf.changedAfterwardsBy || null,
	}
}

/**
 * The as-of answer when OpenRegister's trail does not reach the moment.
 *
 * @param {object|null} history The history, as `fetchAccessHistory` returned it.
 * @return {{answered: boolean, at: string, rows: Array<object>, setBy: string, changedAfterwardsBy: null}} The unanswered set.
 */
function unansweredAsOf(history) {
	return {
		answered: false,
		at: history?.at || '',
		rows: [],
		setBy: '',
		changedAfterwardsBy: null,
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
 * @spec openspec/specs/case-management/spec.md
 */
export function grantRows(answers) {
	const objectPermissions = answers.objectPermissions ?? null
	const rows = objectPermissions !== null
		? objectPermissionRows(objectPermissions)
		: writtenRuleRows(answers)

	// The caller's own row is last and separate: it is the only entry that is
	// an ANSWER rather than a rule, and OpenRegister wrote both the verdict and
	// the source on it.
	return [...rows, ...callerRows(answers.callerScope)]
}

/**
 * The rows of the share, role and deny reads, for an object that answered
 * no permission set of its own.
 *
 * @param {object} answers The reads, as `grantRows` takes them.
 * @return {Array<object>} The rows, shares first, then roles, then denies.
 */
function writtenRuleRows(answers) {
	return [
		...shareRows(answers.objectGrants),
		...roleRows(answers.roleGrants),
		...denyRuleRows(answers.denyRules),
	]
}

/**
 * One row per right of each grant written on this case.
 *
 * @param {Array|null|undefined} objectGrants Grants written on this case.
 * @return {Array<object>} Rows of `{holder, right, source, inheritedFrom, detail}`.
 */
function shareRows(objectGrants) {
	const rows = []
	for (const grant of objectGrants || []) {
		rows.push(...(grant?.actions || []).map((right) => shareRow(grant, right)))
	}
	return rows
}

/**
 * The row for one right of one grant.
 *
 * @param {object} grant A grant written on this case.
 * @param {string} right One of its rights.
 * @return {object} The row.
 */
function shareRow(grant, right) {
	return {
		holder: grantHolder(grant),
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
		source: grantSource(grant),
		inheritedFrom: grant.inheritedFrom || '',
		detail: grant.expires ? String(grant.expires) : '',
	}
}

/**
 * Who a grant is written for: its principal, user or group.
 *
 * @param {object} grant A grant written on this case.
 * @return {string} The holder, or ''.
 */
function grantHolder(grant) {
	return grant.principal || grant.userId || grant.groupId || ''
}

/**
 * The kind of rule a grant is: its own source, else inherited or a share.
 *
 * @param {object} grant A grant written on this case.
 * @return {string} The source key.
 */
function grantSource(grant) {
	return grant.source || (grant.inheritedFrom ? 'inherited' : 'share')
}

/**
 * One row per right of each role.
 *
 * @param {object|null|undefined} roleGrants Roles and the verbs they hold.
 * @return {Array<object>} Rows of `{holder, right, source, detail}`.
 */
function roleRows(roleGrants) {
	const rows = []
	for (const role of roleGrants?.roles || []) {
		rows.push(...(role?.actions || []).map((right) => ({
			holder: role.role || '',
			right,
			source: 'role',
			detail: '',
		})))
	}
	return rows
}

/**
 * One row per deny rule as written, enforced or staged.
 *
 * @param {object|null|undefined} denyRules The deny rules as written.
 * @return {Array<object>} Rows of `{holder, right, source, detail}`.
 */
function denyRuleRows(denyRules) {
	return (denyRules?.rules || []).map((rule) => ({
		holder: rule.principal || '',
		right: rule.action || '',
		source: denyRules.enforcing ? 'deny' : 'staged-deny',
		detail: rule.level ? `${rule.level}: ${rule.subject || ''}` : '',
	}))
}

/**
 * One row per right the caller's own verdict names.
 *
 * @param {object|null|undefined} callerScope The caller's own effective verbs.
 * @return {Array<object>} Rows of `{holder, right, source, granted, detail, stagedDeny}`.
 */
function callerRows(callerScope) {
	return Object.entries(callerScope?.provenance || {})
		.map((entry) => callerRow(entry, callerScope.user || ''))
}

/**
 * The row for one right of the caller's own verdict.
 *
 * @param {Array} entry The right and OpenRegister's record of it.
 * @param {string} holder The caller.
 * @return {object} The row.
 */
function callerRow(entry, holder) {
	const [right, record] = entry
	const verdict = record || {}
	return {
		holder,
		right,
		source: verdict.source || 'unknown',
		granted: verdict.granted,
		detail: verdictDetail(verdict),
		stagedDeny: verdict.stagedDeny || null,
	}
}

/**
 * What the caller's verdict on one right came through: a role or a principal.
 *
 * @param {object} verdict OpenRegister's record of one right.
 * @return {string} The role or principal, or ''.
 */
function verdictDetail(verdict) {
	return verdict.role || verdict.principal || ''
}

/**
 * The schema a case type is an object of.
 *
 * @spec openspec/specs/security-hardening/spec.md
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
 * @spec openspec/specs/security-hardening/spec.md
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
	return { decided, declared: await fetchDeclaredFieldRoleRules(referenceId(caseObject.caseType)) }
}

/**
 * The field rules a case type declares: null when there is no case type or
 * it cannot be read, an empty list when it declares none.
 *
 * @param {string} caseTypeId The case type's id, or the empty string.
 * @return {Promise<Array<object>|null>} The declared rules.
 */
async function fetchDeclaredFieldRoleRules(caseTypeId) {
	if (!caseTypeId) {
		return null
	}

	const caseType = await read(
		`apps/openregister/api/objects/${CASE_REGISTER}/${CASE_TYPE_SCHEMA}/${caseTypeId}`,
	)
	if (caseType === null) {
		return null
	}

	return Array.isArray(caseType.fieldRoleRules) ? caseType.fieldRoleRules : []
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
 * @spec openspec/specs/security-hardening/spec.md
 */
function referenceId(value) {
	if (value && typeof value === 'object') {
		return expandedReferenceId(value)
	}

	return String(value ?? '')
}

/**
 * The id an expanded reference carries: its `@self` id, else its id or uuid.
 *
 * @param {object} value The expanded reference.
 * @return {string} The id, or the empty string.
 */
function expandedReferenceId(value) {
	return String(value['@self']?.id ?? value.id ?? value.uuid ?? '')
}
