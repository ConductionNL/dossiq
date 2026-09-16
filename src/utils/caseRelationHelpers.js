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
export const AARD_RELATIE_TYPES = ['vervolg', 'subject', 'bijdrage', 'samenhang']

/**
 * What one relation is called from the side you are looking at it from.
 *
 * Read `displayLabel` and nothing else. OpenRegister has already picked the
 * right half of the pair for the direction of the row: the near label on
 * `/uses`, the inverse label on `/used`. Choosing between `label` and
 * `inverseLabel` here is exactly how a reverse panel ends up showing the near
 * name, which is the defect openregister#3764 exists to end.
 *
 * A row created before the case schema declared its relation types carries no
 * label and no direction, because the old mirror recorded neither. Those fall
 * back to the type's own word, which is what they read as today.
 *
 * @param {object} relation A relation row from `/api/cases/{id}/relations`.
 * @return {string} Localised label.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
export function relationDisplayLabel(relation) {
	const declared = relation?.displayLabel
	if (typeof declared === 'string' && declared.trim() !== '') {
		return declared
	}

	return relationTypeLabel(relation?.aardRelatie)
}

/**
 * The fallback word for a relation type, for a row the schema cannot name.
 *
 * Not direction-aware, and it cannot be: one word for two ends is the thing
 * `relationDisplayLabel` replaces. Only rows written before the schema
 * declared its relation types reach this.
 *
 * @param {string} aardRelatie Relation type.
 * @return {string} Localised label.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
export function relationTypeLabel(aardRelatie) {
	switch (aardRelatie) {
		case 'vervolg':
			return t('dossiq', 'Follow-up')
		case 'subject':
			return t('dossiq', 'Subject')
		case 'bijdrage':
			return t('dossiq', 'Contribution')
		case 'samenhang':
			return t('dossiq', 'Related')
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
			return t('dossiq', 'A case cannot be related to itself.')
		case 'duplicate':
			return t('dossiq', 'This relation already exists.')
		case 'hierarchy_overlap':
			return t(
				'dossiq',
				'These cases are already linked through the main/sub-case hierarchy.',
			)
		case 'access_denied':
			return t('dossiq', 'You do not have access to one of the cases.')
		case 'invalid_aard_relatie':
			return t('dossiq', 'Select a valid relation type.')
		case 'missing_case_id':
			return t('dossiq', 'A target case and relation type are required.')
		default:
			return t('dossiq', 'Could not save the relation.')
	}
}

/**
 * The typed relations of a case, grouped under what they are called from here.
 *
 * One group per label, so the heading IS the direction's word: a sub-case's
 * parent sits under "deelzaak van" and the parent's children under "heeft
 * deelzaak", rather than both under one name. That is the whole point of the
 * pair, and a flat list labelled per row would bury it.
 *
 * Shaped for `CnRelatedObjectsWidget`'s `extraSections`, which renders
 * `item.label` and nothing else, so the case title carries the note.
 *
 * @param {Array} relations Rows from `/api/cases/{id}/relations`.
 * @return {Array} Sections `{key, label, icon, items}`.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
export function relationSections(relations) {
	const groups = new Map()

	for (const relation of relations || []) {
		const caseId = String(relation?.caseId || '')
		if (caseId === '') {
			continue
		}

		const label = relationDisplayLabel(relation)
		if (!groups.has(label)) {
			groups.set(label, [])
		}

		const title = String(relation?.title || '').trim() || caseId
		const notes = String(relation?.notes || '').trim()
		groups.get(label).push({
			id: caseId,
			label: notes === '' ? title : `${title} (${notes})`,
		})
	}

	return [...groups.entries()].map(([label, items]) => ({
		key: `relation-${label}`,
		label,
		icon: 'LinkVariant',
		items,
	}))
}
