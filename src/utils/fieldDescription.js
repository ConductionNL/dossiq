/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type field (propertyDefinition) described in words for the Velden
 * table: the kind of answer it holds and how it is filled in.
 *
 * @spec openspec/changes/case-type-fields-columns/specs/case-types/spec.md
 */

import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { resolveTranslatable } from './i18nResolver.js'

/**
 * The words for each property type. A type not named here is shown as stored.
 *
 * @return {Object<string, string>} Type to label.
 */
function typeLabels() {
	return {
		string: t('dossiq', 'Text'),
		number: t('dossiq', 'Number'),
		integer: t('dossiq', 'Whole number'),
		boolean: t('dossiq', 'Yes or no'),
		date: t('dossiq', 'Date'),
		email: t('dossiq', 'Email'),
		url: t('dossiq', 'Link'),
		file: t('dossiq', 'File'),
		geo: t('dossiq', 'Location'),
		array: t('dossiq', 'List'),
		object: t('dossiq', 'Group of fields'),
	}
}

/**
 * The kind of answer a field holds: "Text", "Choice list, 6 options",
 * "Text from BAG", "Yes or no, calculated".
 *
 * @param {object} field The field.
 * @return {string} The kind, or '' for a field without a type.
 */
export function fieldKind(field) {
	const row = field && typeof field === 'object' ? field : {}
	const options = Array.isArray(row.enumValues) ? row.enumValues.length : 0
	let kind
	if (row.propertyType === 'enum' || options > 0) {
		const count = n('dossiq', '%n option', '%n options', options)
		kind = t('dossiq', 'Choice list, {options}', { options: count })
	} else {
		const type = typeof row.propertyType === 'string' ? row.propertyType : ''
		kind = typeLabels()[type] || type
	}
	if (kind === '') {
		return ''
	}
	if (typeof row.propertySource === 'string' && row.propertySource !== '') {
		kind = t('dossiq', '{kind} from {source}', { kind, source: row.propertySource.toUpperCase() })
	}
	if (typeof row.calculation === 'string' && row.calculation !== '') {
		kind = t('dossiq', '{kind}, calculated', { kind })
	}
	return kind
}

/**
 * How a field is filled in: read only when it is calculated, else required or
 * optional.
 *
 * @param {object} field The field.
 * @return {string} The words.
 */
export function fieldFill(field) {
	const row = field && typeof field === 'object' ? field : {}
	if (typeof row.calculation === 'string' && row.calculation !== '') {
		return t('dossiq', 'Read only')
	}
	return row.isRequired === true ? t('dossiq', 'Required') : t('dossiq', 'Optional')
}

/**
 * What a person reads for a field: its title in the reader's language, else
 * its name.
 *
 * @param {object} field The field.
 * @return {string} The words.
 */
export function fieldTitle(field) {
	const row = field && typeof field === 'object' ? field : {}
	const title = resolveTranslatable(row.title ?? '').text.trim()
	return title !== '' ? title : String(row.name ?? '')
}

/**
 * Where a field comes from: its own case type, the parent type it inherits
 * from (the blueprint tags `origin` and `originCaseTypeTitle`), or shared. A
 * row without an origin is the type's own.
 *
 * @param {object} field The field.
 * @return {string} The words.
 */
export function fieldOrigin(field) {
	const row = field && typeof field === 'object' ? field : {}
	if (row.origin === 'inherited') {
		const title = typeof row.originCaseTypeTitle === 'string' ? row.originCaseTypeTitle : ''
		return title !== '' ? t('dossiq', 'Inherited from {caseType}', { caseType: title }) : t('dossiq', 'Inherited')
	}
	if (row.origin === 'shared') {
		return t('dossiq', 'Shared')
	}
	return t('dossiq', 'Own field')
}

/**
 * Whether the case type shows a field as a column of its case list
 * (`x-index.columns`, by the field's name).
 *
 * @param {object} field The field.
 * @param {object|null} caseType The case type the page shows.
 * @return {string} Yes or No.
 */
export function fieldInCaseList(field, caseType) {
	const columns = caseType && caseType['x-index'] && Array.isArray(caseType['x-index'].columns) ? caseType['x-index'].columns : []
	const name = field && typeof field.name === 'string' ? field.name : ''
	return name !== '' && columns.includes(name) ? t('dossiq', 'Yes') : t('dossiq', 'No')
}
