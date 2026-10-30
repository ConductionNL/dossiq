/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { translatePlural as n } from '@nextcloud/l10n'

/**
 * The case types a row of integriq's DSO activity overview links, as their
 * names, or for an activity no row links yet how many requests wait.
 *
 * @param {Array<object>} value The row's `caseTypes`.
 * @param {object} row The overview row.
 * @return {string} The cell text.
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-dso-activity-table-shows-what-each-activity-becomes-and-when-it-last-arrived
 */
function dsoCaseTypes(value, row) {
	if (row?.unmapped === true) {
		return n('dossiq', 'Not linked yet: %n request waiting', 'Not linked yet: %n requests waiting', Number(row.waiting) || 0)
	}
	return (Array.isArray(value) ? value : [])
		.map((caseType) => String(caseType?.title || caseType?.reference || '').trim())
		.filter((name) => name !== '')
		.join(', ')
}

/**
 * The variants (case type routes) a row's case types start on, each once.
 *
 * @param {Array<object>} value The row's `caseTypes`.
 * @return {string} The cell text.
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-dso-activity-table-shows-what-each-activity-becomes-and-when-it-last-arrived
 */
function dsoVariants(value) {
	const variants = (Array.isArray(value) ? value : [])
		.map((caseType) => String(caseType?.variant || '').trim())
		.filter((variant) => variant !== '')
	return [...new Set(variants)].join(', ')
}

export const dsoActivityFormatters = { dsoCaseTypes, dsoVariants }
