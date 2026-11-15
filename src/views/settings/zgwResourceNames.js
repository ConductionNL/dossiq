/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The name a person reads for each ZGW resource a mapping is stored under. The
 * keys are the stored keys (some are Dutch ZGW names, some English schema
 * slugs); the values are catalogue keys.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-a-zgw-mapping-row-names-its-resource
 */
export const ZGW_RESOURCE_NAMES = Object.freeze({
	abonnement: 'Subscription',
	applicatie: 'Client application',
	besluitinformatieobject: 'Decision document',
	besluittype: 'Decision type',
	caseType: 'Case type',
	catalogus: 'Catalogue',
	decision: 'Decision',
	eigenschap: 'Property',
	enkelvoudiginformatieobject: 'Document',
	gebruiksrechten: 'Usage rights',
	informatieobjecttype: 'Document type',
	kanaal: 'Channel',
	klantcontact: 'Customer contact',
	objectinformatieobject: 'Object document',
	result: 'Result',
	resultaattype: 'Result type',
	role: 'Role',
	roltype: 'Role type',
	status: 'Status',
	statustype: 'Status type',
	verzending: 'Dispatch',
	zaak: 'Case',
	zaakeigenschap: 'Case property',
	zaakinformatieobject: 'Case document',
	zaakobject: 'Case object',
	zaaktypeinformatieobjecttype: 'Case type document type',
})

/**
 * A ZGW resource key as a person reads it, translated; an unknown key as itself.
 *
 * @param {string} key The stored resource key.
 * @param {(app: string, text: string) => string} t The translate function.
 * @return {string} The name.
 * @spec openspec/specs/admin-settings/spec.md#requirement-a-zgw-mapping-row-names-its-resource
 */
export function zgwResourceName(key, t) {
	const name = ZGW_RESOURCE_NAMES[key]
	return name ? t('dossiq', name) : key
}
