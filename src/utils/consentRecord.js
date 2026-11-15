/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A consent recorded on a case: the form the consent dialog fills, whether it
 * is complete, and the `toestemming` object it becomes.
 *
 * The object is the one the share paths read (CaseTransferConsentGate): it
 * names the receiving party in `recipientParties`, starts on `grantedDate`,
 * lapses after `validTo` and stops counting once `withdrawn` is set. A party
 * written here in a spelling the gate does not compare equal (it trims and
 * ignores case, nothing more) is a consent that never covers a share, so the
 * party is trimmed on the way out and empty entries are dropped.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

/** The schema a consent is stored under. */
export const CONSENT_SCHEMA = 'toestemming'

/** The register a consent is stored in. */
export const CONSENT_REGISTER = 'dossiq'

/**
 * A date as YYYY-MM-DD, or the empty string.
 *
 * @param {Date|string|null|undefined} value A picked date or an ISO string.
 * @return {string} The day, or ''.
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
export function isoDay(value) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	if (typeof value === 'string') {
		return /^\d{4}-\d{2}-\d{2}/.test(value) ? value.slice(0, 10) : ''
	}
	const picked = new Date(value)
	if (Number.isNaN(picked.getTime())) {
		return ''
	}
	const month = String(picked.getMonth() + 1).padStart(2, '0')
	const day = String(picked.getDate()).padStart(2, '0')
	return `${picked.getFullYear()}-${month}-${day}`
}

/**
 * The receiving parties of a form, trimmed, without empties or repeats.
 *
 * @param {string|Array<string>} parties One party, a comma separated list, or a list.
 * @return {Array<string>} The parties.
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
export function consentParties(parties) {
	const list = Array.isArray(parties) ? parties : String(parties ?? '').split(',')
	const seen = new Set()
	const named = []
	for (const entry of list) {
		const party = String(entry ?? '').trim()
		if (party !== '' && !seen.has(party.toLowerCase())) {
			seen.add(party.toLowerCase())
			named.push(party)
		}
	}
	return named
}

/**
 * Why a consent form cannot be saved yet, or '' when it can.
 *
 * @param {object} form The form: grantedByName, grantedByBsn, parties, purpose, from, until.
 * @return {string} The first missing piece as a rule id, or ''.
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
export function consentFormProblem(form) {
	const value = form || {}
	if (!/^\d{9}$/.test(String(value.grantedByBsn ?? '').trim())) {
		return 'bsn'
	}
	if (consentParties(value.parties).length === 0) {
		return 'party'
	}
	if (String(value.purpose ?? '').trim() === '') {
		return 'purpose'
	}
	const from = isoDay(value.from)
	if (from === '') {
		return 'from'
	}
	const until = isoDay(value.until)
	if (until !== '' && until < from) {
		return 'until'
	}
	return ''
}

/**
 * The `toestemming` object a complete form becomes.
 *
 * @param {object} form The form, complete per consentFormProblem.
 * @param {string} caseId The case the consent is recorded on.
 * @return {object} The object to save.
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
export function consentPayload(form, caseId) {
	const payload = {
		caseId: String(caseId ?? '').trim(),
		grantedByBsn: String(form.grantedByBsn ?? '').trim(),
		recipientParties: consentParties(form.parties),
		tedoel: String(form.purpose ?? '').trim(),
		grantedDate: isoDay(form.from),
		withdrawn: false,
		withdrawalPossible: true,
	}
	const name = String(form.grantedByName ?? '').trim()
	if (name !== '') {
		payload.grantedByName = name
	}
	const until = isoDay(form.until)
	if (until !== '') {
		payload.validTo = until
	}
	return payload
}
