/**
 * Who a document came from, and who it went to.
 *
 * 🔴 A CORRESPONDENT IS A PARTY OF THE CASE, NEVER A TYPED NAME. The picker
 * offers exactly the parties the case has and stores their identifier, which
 * is what lets a list group on a person, filter on them and stay right when
 * the party is corrected. `dispatch.contactPersonName` has been in the
 * register since the ZGW import and nothing ever wrote it; a free-text field
 * is the shape this replaces, not the shape it copies.
 *
 * 🔴 THE OPTIONS COME FROM THE SAME LISTING THE PEOPLE TAB RENDERS.
 * `fetchCaseParties` answers the links with the kinds and roles the case
 * schema declares, so a picker here and the People tab beside it cannot drift
 * apart. A second endpoint of our own would be a second thing to keep in
 * step, which is the whole of ADR-022.
 *
 * WHY THE DIRECTION NARROWS THE FORM. An outgoing document names addressees
 * and an incoming one names a sender. Offering both on both is how a letter
 * ends up claiming it was sent and received at once. The server drops the
 * contradiction either way; the dialog just stops offering it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

/**
 * The value a document stores for one party of the case.
 *
 * The party uuid when it has one, the contact uid otherwise. A link written
 * before the party model carries only the second, and those links are on real
 * cases: refusing them would make the sender of every document on an older
 * case unfillable.
 *
 * @param {object} party The party link, as the parties listing answers it.
 * @return {string} The identifier, '' when the link names neither.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function partyIdentifier(party) {
	if (!party || typeof party !== 'object') {
		return ''
	}
	const uuid = trimmedText(party.partyUuid)
	if (uuid !== '') {
		return uuid
	}
	return trimmedText(party.contactUid)
}

/**
 * A stored value as trimmed text, '' when it is empty or missing.
 *
 * @param {string|number|null|undefined} value The value.
 * @return {string} The text.
 */
function trimmedText(value) {
	return String(value || '').trim()
}

/**
 * The picker's options: every party of the case, once each, with a label.
 *
 * A party in two roles is one option, not two. The People tab groups by role
 * because a role is what a party DOES on the case; a correspondent picker
 * asks who, and offering the same person twice under two headings is how a
 * handler picks the wrong one of two identical rows.
 *
 * @param {object|null} listing The parties listing, or null when it failed.
 * @return {Array<object>} `{id, label, email}` per party, in listing order.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function partyOptions(listing) {
	const rows = Array.isArray(listing?.results) ? listing.results : []
	const options = []
	const seen = []
	for (const row of rows) {
		const id = partyIdentifier(row)
		if (id === '' || seen.includes(id)) {
			continue
		}
		seen.push(id)
		options.push(partyOption(row, id))
	}
	return options
}

/**
 * One picker option for a party row: its name, else its contact uid, else its id.
 *
 * @param {object} row The party link.
 * @param {string} id The party's identifier.
 * @return {object} `{id, label, email}`.
 */
function partyOption(row, id) {
	return {
		id,
		label: String(row.displayName || row.contactUid || id).trim() || id,
		email: trimmedText(row.email),
	}
}

/**
 * What to show for the correspondents a document already stores.
 *
 * An identifier the listing no longer knows keeps itself as its label rather
 * than vanishing. A row that silently loses its sender tells a handler the
 * letter was never addressed; an unreadable identifier at least gets asked
 * about.
 *
 * @param {Array<string>|string} stored The stored identifier or identifiers.
 * @param {Array<object>} options The picker options.
 * @return {Array<object>} The matching options, in the order stored.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function selectedOptions(stored, options) {
	const wanted = Array.isArray(stored) ? stored : [stored]
	const chosen = []
	for (const entry of wanted) {
		const id = trimmedText(entry)
		if (id === '' || hasOptionFor(chosen, id)) {
			continue
		}
		chosen.push(storedOption(options, id))
	}
	return chosen
}

/**
 * Whether a list of options already holds the one for an identifier.
 *
 * @param {Array<object>} options The options.
 * @param {string} id The identifier.
 * @return {boolean} True when one of them carries it.
 */
function hasOptionFor(options, id) {
	return options.some((option) => option.id === id)
}

/**
 * The option for a stored identifier, or one labelled with the identifier itself.
 *
 * @param {Array<object>} options The picker options.
 * @param {string} id The stored identifier.
 * @return {object} `{id, label, email}`.
 */
function storedOption(options, id) {
	return (
		options.find((option) => option.id === id) || {
			id,
			label: id,
			email: '',
		}
	)
}

/**
 * The identifiers a picker's value stores.
 *
 * A NcSelect hands back the option OBJECT it was given, and a single picker
 * hands back one where a multiple picker hands back a list. Sending either
 * straight to a string property is a 400 nobody sees until they press Save.
 *
 * @param {Array<object>|object|string|null} value The picker's value.
 * @return {Array<string>} The identifiers, deduplicated, blanks dropped.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function identifiersOf(value) {
	const entries = Array.isArray(value) ? value : [value]
	const ids = []
	for (const entry of entries) {
		const trimmed = entryIdentifier(entry).trim()
		if (trimmed !== '' && !ids.includes(trimmed)) {
			ids.push(trimmed)
		}
	}
	return ids
}

/**
 * The identifier one picker entry carries: a bare value, or an object's id.
 *
 * @param {object|string|number|null} entry One entry of the picker's value.
 * @return {string} The identifier, untrimmed.
 */
function entryIdentifier(entry) {
	return typeof entry === 'string' || typeof entry === 'number'
		? String(entry)
		: objectIdentifier(entry)
}

/**
 * The identifier of an option object or party link: its id, else its party identifier.
 *
 * @param {object|null|undefined} entry The option object or link.
 * @return {string} The identifier, untrimmed.
 */
function objectIdentifier(entry) {
	return String((entry || {}).id || partyIdentifier(entry) || '')
}

/**
 * Which correspondent fields a document of this direction may carry.
 *
 * Mirrors `DocumentCorrespondents::allowedFor()` on the server, which is the
 * one that decides. This is what the dialog draws, so a person is not offered
 * a field their save is going to drop.
 *
 * @param {string} direction The document's direction.
 * @return {object} `{sender, recipients}`, each a boolean.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function allowedFor(direction) {
	const value = String(direction || '').trim()
	if (value === 'outgoing') {
		return { sender: false, recipients: true }
	}
	if (value === 'incoming') {
		return { sender: true, recipients: false }
	}
	return { sender: true, recipients: true }
}
