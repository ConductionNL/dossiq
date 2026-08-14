/**
 * Initiator (indiener) cross-source search helpers (brp-kvk-register-sets).
 *
 * Pure result-shaping helpers plus thin search functions over the three
 * initiator sources: the seeded `brpPerson` / `kvkCompany` register sets
 * (OpenRegister objects API via the object store — thin client, no procest
 * backend CRUD wrapper) and Nextcloud contacts (core /contactsmenu/contacts
 * endpoint; degrades to an empty list when unavailable — never an error).
 *
 * The register → live BRP/KvK adapter fallback is owned by
 * `external-integrations-test-environments`; this module queries the
 * register tier only.
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
	return [naam.voornamen, naam.voorvoegsel, naam.geslachtsnaam]
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
	return {
		type: 'person',
		sourceId: person.burgerservicenummer || '',
		displayName: person.displayName || personDisplayName(person.naam),
		detail: [
			person.geboorte?.datum,
			person.burgerservicenummer && `BSN ${person.burgerservicenummer}`,
		]
			.filter(Boolean)
			.join(' · '),
		objectId: person.id || person['@self']?.id || null,
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
	return {
		type: 'company',
		sourceId: company.kvkNummer || '',
		displayName: company.handelsnaam || '',
		detail: [company.rechtsvorm, company.kvkNummer && `KVK ${company.kvkNummer}`]
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
