/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The anonymised export of a case list: the Cases page's bulk action that
 * sends the selected cases to `POST /api/cases/anonymised-export` and saves
 * what comes back as a CSV file.
 *
 * The server does the anonymising (each case type's `identifyingFields`
 * removed or generalised, `@self` dropped) and writes the processing-log row;
 * this file only turns its answer into a file. It never falls back to the
 * ordinary export: a refusal is shown, because a plain file handed out where
 * an anonymised one was asked for is the one outcome this action exists to
 * prevent.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The endpoint that anonymises the cases. */
export const ANONYMISED_EXPORT_URL = '/apps/dossiq/api/cases/anonymised-export'

/**
 * One CSV cell: nested values as JSON, quoted when needed.
 *
 * @param {unknown} value The value.
 * @return {string} The cell.
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
export function csvCell(value) {
	if (value === null || value === undefined) {
		return ''
	}
	const text = typeof value === 'object' ? JSON.stringify(value) : String(value)
	return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

/**
 * The anonymised cases as CSV: one column per field any case carries, in the
 * order they first appear.
 *
 * @param {Array<object>} cases The anonymised cases.
 * @return {string} The CSV text, '' for no cases.
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
export function anonymisedCsv(cases) {
	const rows = (Array.isArray(cases) ? cases : []).filter((row) => row && typeof row === 'object')
	if (rows.length === 0) {
		return ''
	}
	const columns = []
	for (const row of rows) {
		for (const key of Object.keys(row)) {
			if (!columns.includes(key)) {
				columns.push(key)
			}
		}
	}
	const lines = [columns.map(csvCell).join(',')]
	for (const row of rows) {
		lines.push(columns.map((key) => csvCell(row[key])).join(','))
	}
	return lines.join('\r\n') + '\r\n'
}

/**
 * Save text as a file in the browser.
 *
 * @param {string} text The content.
 * @param {string} filename The file name.
 * @return {void}
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
function saveFile(text, filename) {
	const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }))
	const link = document.createElement('a')
	link.href = url
	link.download = filename
	document.body.appendChild(link)
	link.click()
	link.remove()
	URL.revokeObjectURL(url)
}

/**
 * Export the selected cases anonymised.
 *
 * @param {object} args The bulk action arguments.
 * @param {Array<string>} args.selectedIds The selected case ids.
 * @return {Promise<boolean>} Whether a file was saved.
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
export async function exportAnonymisedSelection({ selectedIds } = {}) {
	const caseIds = (Array.isArray(selectedIds) ? selectedIds : []).map(String).filter((id) => id !== '')
	if (caseIds.length === 0) {
		return false
	}
	let cases
	try {
		const { data } = await axios.post(generateUrl(ANONYMISED_EXPORT_URL), { caseIds })
		cases = Array.isArray(data?.cases) ? data.cases : []
	} catch (e) {
		showError(e?.response?.data?.error || t('dossiq', 'The anonymised export failed'))
		return false
	}
	const csv = anonymisedCsv(cases)
	if (csv === '') {
		showError(t('dossiq', 'None of the selected cases could be exported'))
		return false
	}
	saveFile(csv, `cases-anonymised-${new Date().toISOString().slice(0, 10)}.csv`)
	showSuccess(n('dossiq', 'Exported %n case anonymised', 'Exported %n cases anonymised', cases.length))
	return true
}
