/**
 * Pure presentation helpers for typed peer case relations
 * (related-case-linking). Kept free of NC-network imports so they can be
 * unit-tested in a plain node environment (see tests/vitest/caseRelationApi.spec.js).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

/**
 * The three RGBZ/ZRC relation types (aardRelatie).
 *
 * @type {Array<string>}
 */
export const AARD_RELATIE_TYPES = ['vervolg', 'subject', 'bijdrage']

/**
 * Direction-aware label for a relation type. The same type names both sides of
 * a symmetric link; the UI renders the appropriate human phrasing.
 *
 * @param {string} aardRelatie Relation type.
 * @return {string} Localised label.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
export function relationTypeLabel(aardRelatie) {
	switch (aardRelatie) {
		case 'vervolg':
			return t('procest', 'Follow-up')
		case 'subject':
			return t('procest', 'Subject')
		case 'bijdrage':
			return t('procest', 'Contribution')
		default:
			return aardRelatie
	}
}

/**
 * Map a guard-reason code to a localised, user-facing message.
 *
 * @param {string} reason Guard reason code from the API.
 * @return {string} Localised message.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
export function relationErrorMessage(reason) {
	switch (reason) {
		case 'self_relation':
			return t('procest', 'A case cannot be related to itself.')
		case 'duplicate':
			return t('procest', 'This relation already exists.')
		case 'hierarchy_overlap':
			return t(
				'procest',
				'These cases are already linked through the main/sub-case hierarchy.',
			)
		case 'access_denied':
			return t('procest', 'You do not have access to one of the cases.')
		case 'invalid_aard_relatie':
			return t('procest', 'Select a valid relation type.')
		case 'missing_case_id':
			return t('procest', 'A target case and relation type are required.')
		default:
			return t('procest', 'Could not save the relation.')
	}
}
