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

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

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
	const bsn = person.citizenServiceNumber || person.citizen_service_number || ''
	return {
		type: 'person',
		sourceId: bsn,
		displayName: person.displayName || personDisplayName(person.name),
		detail: [person.birth?.date, bsn && `BSN ${bsn}`]
			.filter(Boolean)
			.join(' · '),
		objectId: person.id || person['@self']?.id || null,
		protected: person.indicatieGeheim === true,
	}
}

/**
 * Normalise a kvkCompany register object into a unified initiator result.
 *
 * @param {object} company The kvkCompany object.
 * @return {object} {type, sourceId, displayName, detail, objectId}.
 * @spec openspec/specs/initiator-selection/spec.md
 */
export function companyResult(company) {
	const number = company.kvkNumber || company.kvk_number || ''
	return {
		type: 'company',
		sourceId: number,
		displayName: company.tradeName || company.trade_name || '',
		detail: [company.legalForm || company.legal_form, number && `KVK ${number}`]
			.filter(Boolean)
			.join(' · '),
		objectId: company.id || company['@self']?.id || null,
	}
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
		sourceId:
			contact.id
			|| contact.uid
			|| contact.emailAddresses?.[0]
			|| contact.fullName
			|| '',
		displayName: contact.fullName || contact.id || '',
		detail: contact.emailAddresses?.[0] || contact.topAction?.title || '',
		objectId: null,
	}
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
 * Whether a unified result is the one a requester payload names.
 *
 * The payload is what the form holds, so the picker has to recognise its
 * own choice coming back in. A bare uuid string is accepted too: that is
 * what `case.requester` holds on a case whose projection was written by
 * the semantic handoff rather than by the picker.
 *
 * @param {object|string|null} value The current requester payload, uuid, or null.
 * @param {object} result A unified initiator result.
 * @return {boolean} True when the result is the current choice.
 * @spec openspec/specs/initiator-selection/spec.md
 */
export function isCurrentRequester(value, result) {
	if (!value || !result) {
		return false
	}
	if (typeof value === 'string') {
		return !!result.objectId && value === result.objectId
	}
	if (value.initiatorType) {
		return (
			value.initiatorType === result.type
			&& String(value.initiatorSourceId || '') === String(result.sourceId || '')
		)
	}
	// A bare unified result (the shape the picker emitted before the
	// payload carried the uuid).
	return (
		value.type === result.type
		&& String(value.sourceId || '') === String(result.sourceId || '')
	)
}

/**
 * Mask an identifying number to five dots plus its last four digits.
 *
 * The last four are kept on purpose: a handler has to be able to tell two
 * cases apart, and a fully hidden number makes the card useless without
 * revealing it. The number itself is still on the case object — this is a
 * display rule, not storage masking, which needs OpenRegister to mask a
 * field on read.
 *
 * @param {string} value The number to mask.
 * @return {string} The masked number, or '' for an empty value.
 * @spec openspec/specs/initiator-display/spec.md
 */
export function maskNumber(value) {
	const text = String(value || '')
	if (text === '') {
		return ''
	}
	return `•••••${text.slice(-4)}`
}

/**
 * Search Nextcloud contacts through the core contactsmenu endpoint.
 * Degrades to [] on any failure (Contacts absent, endpoint disabled) —
 * the picker shows an explicit empty state, never an error toast.
 *
 * @param {string} query The search filter.
 * @return {Promise<Array<object>>} Unified contact results.
 * @spec openspec/specs/initiator-selection/spec.md
 */
export async function searchContacts(query) {
	try {
		const { data } = await axios.post(generateUrl('/contactsmenu/contacts'), {
			filter: query,
		})
		const contacts = data?.contacts || []
		return contacts.map(contactResult)
	} catch (err) {
		// Graceful degradation by spec — unavailable source is an empty state.
		return []
	}
}
