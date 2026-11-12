/**
 * Initiator (indiener) cross-source search helpers (brp-kvk-register-sets).
 *
 * Pure result-shaping helpers plus thin search functions over the three
 * initiator sources: the seeded `brpPerson` / `kvkCompany` register sets
 * (OpenRegister objects API via the object store — thin client, no dossiq
 * backend CRUD wrapper) and Nextcloud contacts (core /contactsmenu/contacts
 * endpoint; degrades to an empty list when unavailable — never an error).
 *
 * The register → live BRP/KvK adapter fallback is owned by
 * `external-integrations-test-environments`; this module queries the
 * register tier only.
 *
 * Both key casings are read, deliberately. The seeded rows carry the
 * schema's own camelCase names (`citizenServiceNumber`, `tradeName`) while
 * these helpers were written against snake_case, and the mismatch never
 * showed because the register search could not run at all: `brpPerson` and
 * `kvkCompany` were not registered object types, so every search threw and
 * was caught as "no matching records".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */

import { resolvePartyByAddress } from './caseParties.js'

/**
 * Compose a person display name from a Haal Centraal naam block.
 *
 * @param {object} naam The naam block ({voornamen, voorvoegsel, geslachtsnaam}).
 * @return {string} The display name.
 * @spec openspec/specs/initiator-selection/spec.md
 */
export function personDisplayName(naam) {
	if (!naam) {
		return ''
	}
	return [
		naam.givenNames ?? naam.given_names,
		naam.namePrefix ?? naam.name_prefix,
		naam.surname,
	]
		.filter((part) => !!part && String(part).trim() !== '')
		.join(' ')
}

/**
 * Normalise a brpPerson register object into a unified initiator result.
 *
 * @param {object} person The brpPerson object.
 * @return {object} {type, sourceId, displayName, detail, objectId}.
 * @spec openspec/specs/initiator-selection/spec.md
 */
export function personResult(person) {
	const bsn = citizenServiceNumberOf(person)
	return {
		type: 'person',
		sourceId: bsn,
		displayName: person.displayName || personDisplayName(person.name),
		detail: [person.birth?.date, bsn && `BSN ${bsn}`]
			.filter(Boolean)
			.join(' · '),
		objectId: registerObjectId(person),
		protected: person.indicatieGeheim === true,
	}
}

/**
 * A brpPerson row's BSN, in either key casing.
 *
 * @param {object} person The brpPerson object.
 * @return {string} The BSN, '' when it has none.
 */
function citizenServiceNumberOf(person) {
	return person.citizenServiceNumber || person.citizen_service_number || ''
}

/**
 * A register row's own uuid, from the row or its `@self` block.
 *
 * @param {object} row The register object.
 * @return {string|null} The uuid, null when it has none.
 */
function registerObjectId(row) {
	return row.id || row['@self']?.id || null
}

/**
 * Normalise a kvkCompany register object into a unified initiator result.
 *
 * @param {object} company The kvkCompany object.
 * @return {object} {type, sourceId, displayName, detail, objectId}.
 * @spec openspec/specs/initiator-selection/spec.md
 */
export function companyResult(company) {
	const number = kvkNumberOf(company)
	return {
		type: 'company',
		sourceId: number,
		displayName: tradeNameOf(company),
		detail: [legalFormOf(company), number && `KVK ${number}`]
			.filter(Boolean)
			.join(' · '),
		objectId: registerObjectId(company),
	}
}

/**
 * A kvkCompany row's KVK number, in either key casing.
 *
 * @param {object} company The kvkCompany object.
 * @return {string} The number, '' when it has none.
 */
function kvkNumberOf(company) {
	return company.kvkNumber || company.kvk_number || ''
}

/**
 * A kvkCompany row's trade name, in either key casing.
 *
 * @param {object} company The kvkCompany object.
 * @return {string} The name, '' when it has none.
 */
function tradeNameOf(company) {
	return company.tradeName || company.trade_name || ''
}

/**
 * A kvkCompany row's legal form, in either key casing.
 *
 * @param {object} company The kvkCompany object.
 * @return {string|undefined} The legal form as stored.
 */
function legalFormOf(company) {
	return company.legalForm || company.legal_form
}

/**
 * Normalise a core contactsmenu entry into a unified initiator result.
 *
 * @param {object} contact The contactsmenu contact entry.
 * @return {object} {type, sourceId, displayName, detail, objectId}.
 * @spec openspec/specs/initiator-selection/spec.md
 */
export function contactResult(contact) {
	return {
		type: 'contact',
		sourceId: contactSourceId(contact),
		displayName: contact.fullName || contact.id || '',
		detail: contactDetail(contact),
		objectId: null,
	}
}

/**
 * The identifier a contact is recorded under: its id, uid, first address or name.
 *
 * @param {object} contact The contactsmenu contact entry.
 * @return {string} The identifier, '' when it has none.
 */
function contactSourceId(contact) {
	return (
		contact.id
		|| contact.uid
		|| contact.emailAddresses?.[0]
		|| contact.fullName
		|| ''
	)
}

/**
 * The line under a contact: its first address, else its top action.
 *
 * @param {object} contact The contactsmenu contact entry.
 * @return {string} The detail, '' when it has neither.
 */
function contactDetail(contact) {
	return contact.emailAddresses?.[0] || contact.topAction?.title || ''
}

/**
 * Map a picked initiator result onto the case projection fields.
 *
 * These are the display projection of the canonical ADR-048 requester
 * semantic reference (owned by semantic-case-intake); one write path.
 *
 * @param {object|null} result A unified initiator result (or null = none picked).
 * @return {object} Partial case payload ({} when no initiator).
 * @spec openspec/specs/initiator-selection/spec.md
 */
export function initiatorProjection(result) {
	if (!result || !result.type) {
		return {}
	}
	return {
		initiatorType: result.type,
		initiatorSourceId: String(result.sourceId || ''),
		initiatorDisplayName: result.displayName || '',
	}
}

/**
 * Map a picked initiator result onto the four fields the case carries for
 * its requester: the canonical reference plus its display projection.
 *
 * `requester` is the uuid of the chosen `brpPerson` or `kvkCompany` row.
 * A Nextcloud contact has no register row, so a contact selection fills the
 * projection and leaves `requester` empty — the same shape a case that
 * named a contact has today.
 *
 * One write path: the form writes all four fields in one save, and nothing
 * else writes a second requester field.
 *
 * @param {object|null} result A unified initiator result (or null = none picked).
 * @return {object} Partial case payload ({} when no initiator).
 * @spec openspec/specs/initiator-selection/spec.md
 */
export function requesterPayload(result) {
	const projection = initiatorProjection(result)
	if (Object.keys(projection).length === 0) {
		return {}
	}
	return {
		requester: String(result.objectId || ''),
		...projection,
	}
}

/**
 * A picked party as the four fields a case carries for its requester, from
 * the kind it was found as (`person`, `company`, `contact`) and its row.
 *
 * A Nextcloud contact has no register row, so it is pointed at the party that
 * already holds its address when OpenRegister knows one: two cases from one
 * address then name one record, not two that no merge would find. A register
 * row is already the record and is taken as it is.
 *
 * @param {string} kind The source the row came from.
 * @param {object} row The row.
 * @return {Promise<object>} `{ requester, initiatorType, initiatorSourceId, initiatorDisplayName }`.
 * @spec openspec/specs/initiator-selection/spec.md
 * @spec openspec/specs/roles-decisions/spec.md
 */
export async function initiatorChoice(kind, row) {
	const shapes = { person: personResult, company: companyResult, contact: contactResult }
	const shape = shapes[kind]
	if (!shape || !row) {
		return {}
	}
	const result = shape(row)
	if (needsAddressLookup(result)) {
		await adoptPartyHoldingAddress(result)
	}
	return requesterPayload(result)
}

/**
 * The address a result's detail line holds, trimmed.
 *
 * @param {object} result A unified initiator result.
 * @return {string} The detail as an address candidate.
 */
function addressOf(result) {
	return String(result.detail || '').trim()
}

/**
 * Whether a result has no register row but names an address a party may hold.
 *
 * @param {object} result A unified initiator result.
 * @return {boolean} True when the address should be looked up.
 */
function needsAddressLookup(result) {
	return !result.objectId && addressOf(result).includes('@')
}

/**
 * Point a result at the party that already holds its address, when one does.
 *
 * @param {object} result A unified initiator result; its `objectId` is set.
 * @return {Promise<void>}
 */
async function adoptPartyHoldingAddress(result) {
	const party = await resolvePartyByAddress(addressOf(result))
	if (party) {
		result.objectId = partyObjectId(party)
	}
}

/**
 * A party record's uuid, whichever shape OpenRegister answered it in.
 *
 * @param {object} party The party record.
 * @return {string|null} The uuid, null when it has none.
 */
function partyObjectId(party) {
	return party.id || party['@self']?.id || party.uuid || null
}
