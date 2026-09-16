/**
 * What the duplicate panel offers, decided in one place.
 *
 * Three inputs decide it: what the platform matched, what the case type
 * declared, and whether this account is in an override group. Keeping the
 * decision here rather than in the modal's template is what makes it testable
 * without a browser, and it is the reason the same answer can be asserted
 * against the server's, which enforces the identical rule on the write.
 *
 * 🔴 NONE OF THIS IS THE ENFORCEMENT. `DuplicatePolicy` refuses the create on
 * the pre-persist event, and it goes on refusing for an import or an
 * integration that never opens this panel. What these helpers buy is that a
 * handler is told before they press Create rather than after the save failed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */

/** The case type lets anybody file the case after reading the warning. */
export const POLICY_WARN = 'warn'

/** Only an override group may file the case, and they say why. */
export const POLICY_BLOCK = 'block'

/**
 * Read a policy value, however it arrived.
 *
 * Anything that is not `block` reads as `warn`, mirroring the server: a typo in
 * an administrator's case type must not stop an intake desk filing cases.
 *
 * @param {string|null|undefined} value The declared policy.
 * @return {string} Either `warn` or `block`.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export function normalisePolicy(value) {
	return value === POLICY_BLOCK ? POLICY_BLOCK : POLICY_WARN
}

/**
 * What the panel shows and which buttons it offers.
 *
 * `checked: false` means the question was never answered, so there is nothing
 * to show. An unanswered check is not an all clear, but it is also not a
 * warning about cases nobody found: the write path is what refuses a real
 * duplicate, and it runs whether this call worked or not.
 *
 * @param {object} state The state to decide from.
 * @param {Array<object>} [state.matches] What the platform matched.
 * @param {boolean} [state.checked] Whether the check actually ran.
 * @param {string} [state.policy] What the case type declared.
 * @param {boolean} [state.mayOverride] Whether this account is in an override group.
 * @return {{show: boolean, blocked: boolean, mayContinue: boolean, needsReason: boolean}} What to draw.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export function affordancesFor(state) {
	const source = state || {}
	const matches = Array.isArray(source.matches) ? source.matches : []
	const show = source.checked === true && matches.length > 0
	const blocked = show && normalisePolicy(source.policy) === POLICY_BLOCK
	const mayOverride = source.mayOverride === true

	return {
		show,
		blocked,
		mayContinue: show && (blocked === false || mayOverride),
		needsReason: blocked && mayOverride,
	}
}

/**
 * Whether the create may be sent, given what the handler answered.
 *
 * @param {object} state The affordances, plus the reason typed so far.
 * @param {boolean} [state.mayContinue] Whether continuing is offered at all.
 * @param {boolean} [state.needsReason] Whether a reason is required.
 * @param {string} [state.reason] What was typed.
 * @return {boolean} True when Create may be pressed.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export function mayFile(state) {
	const source = state || {}
	if (source.mayContinue !== true) {
		return false
	}

	if (source.needsReason !== true) {
		return true
	}

	return String(source.reason || '').trim() !== ''
}

/**
 * The fields that made this match, as words.
 *
 * Reads the platform's `matchedOn`, so the sentence names the same fields the
 * scorer used. An empty list says so rather than claiming a reason: "it scored
 * high on nothing" is the answer a handler needs in order to report the rule
 * as wrong.
 *
 * @param {object} match One scored match.
 * @param {(key: string) => string} [translate] The app's translate function.
 * @return {string} One short sentence.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export function matchedOnSentence(match, translate) {
	const t = typeof translate === 'function' ? translate : (key) => key
	const fields = Array.isArray(match?.matchedOn) ? match.matchedOn : []
	const labels = {
		requester: t('Requester'),
		title: t('Subject'),
		caseType: t('Case type'),
	}
	const named = fields.map((field) => labels[field] || field)

	if (named.length === 0) {
		return t('Matched on the score, not on a single field')
	}

	return named.join(', ')
}

/**
 * How strongly this case matched, as a whole percentage.
 *
 * @param {object} match One scored match.
 * @return {number} 0 to 100.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export function matchPercentage(match) {
	const score = Number(match?.score)
	if (Number.isFinite(score) === false) {
		return 0
	}

	return Math.max(0, Math.min(100, Math.round(score * 100)))
}

/**
 * What to call a matched case, out of what could be read of it.
 *
 * A case the handler may not read comes back as null, and the panel says a case
 * matched without naming it. That is deliberate: the collision is reported, the
 * record is not disclosed.
 *
 * @param {object} match One scored match.
 * @param {Array<object>} cases The cases that could be read.
 * @param {(key: string) => string} [translate] The app's translate function.
 * @return {{uuid: string, title: string, identifier: string, readable: boolean}} The row to draw.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export function matchRow(match, cases, translate) {
	const t = typeof translate === 'function' ? translate : (key) => key
	const uuid = String(match?.uuid || '')
	const found = (Array.isArray(cases) ? cases : []).find(
		(row) => String(row?.id || row?.uuid || row?.['@self']?.id || '') === uuid,
	)

	return {
		uuid,
		title: found?.title || t('A case you may not open'),
		identifier: found?.identifier || '',
		readable: Boolean(found),
	}
}
