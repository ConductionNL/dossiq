/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Where dossiq searches for a party: the brpPerson and kvkCompany register
 * sets (brp-kvk-register-sets), and for the start-a-case flow also the
 * reader's Nextcloud contacts. CnPartyPicker sources; the `id` of each is the
 * kind a choice is recorded as (`initiatorType`).
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */

/** @type {Array<object>} The register sets, persons first. */
export const REGISTER_PARTY_SOURCES = Object.freeze([
	{ id: 'person', register: 'dossiq', schema: 'brpPerson', label: 'Resident', title: '{displayName}', secondary: '{residence.street} {residence.houseNumber}' },
	{ id: 'company', register: 'dossiq', schema: 'kvkCompany', label: 'Organisation', title: '{tradeName}', secondary: '{kvkNumber}' },
])

/**
 * The register sets and the reader's Nextcloud contacts, for picking who
 * started a case: a person by birth date and BSN, a company by KvK number,
 * so two people of one name are told apart.
 *
 * @type {Array<object>}
 */
export const PARTY_SOURCES_WITH_CONTACTS = Object.freeze([
	{ id: 'person', register: 'dossiq', schema: 'brpPerson', label: 'Resident', title: '{displayName}', secondary: '{birth.date} · BSN {citizenServiceNumber}' },
	{ id: 'company', register: 'dossiq', schema: 'kvkCompany', label: 'Organisation', title: '{tradeName}', secondary: 'KVK {kvkNumber}' },
	{ id: 'contact', type: 'contacts', label: 'Contact' },
])
