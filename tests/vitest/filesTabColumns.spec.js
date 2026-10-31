/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Files tab shows each file's name.
 *
 * Rehearsal R2 (15 Oct): the case's Files tab listed icon-only rows. The
 * manifest declared its four columns as `{id, property}`, the library drops a
 * column without `key`, and a non-empty list replaces the library's default
 * of name, size and modified, so nothing was left. This runs the declaration
 * through the library's own normaliser, the one CnFilesBrowser renders from.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-the-case-page-shows-labels-and-names-not-codes-req-cm-82
 */

import { normaliseFileColumns } from '@conduction/nextcloud-vue/src/components/CnFilesBrowser/filesBrowserColumns.js'
import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

/**
 * The props the Files tab hands the browser.
 *
 * @return {object} The props.
 */
function filesProps() {
	return panels.caseWidget('case-files').props
}

/**
 * The declared columns on an instance with the virus scanner: a column that
 * requires files_antivirus applies (the test runs with no app enabled).
 *
 * @param {Array<object>} columns The declared columns.
 * @return {Array<object>} The same columns without `requiredApp`.
 */
function withScanner(columns) {
	return columns.map((column) => {
		if (
			column === null
			|| typeof column !== 'object'
			|| column.requiredApp === undefined
		) {
			return column
		}
		const { requiredApp, ...rest } = column
		return rest
	})
}

describe('the Files tab columns as the library reads them', () => {
	it('renders the name, then the board columns, and keeps the rest in the side cards', () => {
		const columns = normaliseFileColumns(withScanner(filesProps().columns))
		// Without the virus scanner the library leaves the Scan column out.
		expect(
			normaliseFileColumns(filesProps().columns).map((column) => column.key),
		).not.toContain('scanVerdict')
		// A column with `when` (the review verdict) is drawn only on a case type that reviews its documents.
		const keys = columns
			.filter((column) => column.hidden !== true && column.when === undefined)
			.map((column) => column.key)

		expect(keys).toEqual(['name', 'scanVerdict', 'vertrouwelijkheidaanduiding'])
		// The library keeps a `when` column only once the page resolved its condition to true.
		expect(columns.map((column) => column.key)).not.toContain('reviewVerdict')
		const resolved = withScanner(filesProps().columns).map((column) =>
			column?.when !== undefined ? { ...column, when: true } : column,
		)
		expect(normaliseFileColumns(resolved).map((column) => column.key)).toContain(
			'reviewVerdict',
		)
		// Document type, Direction, Sender or recipient and Keywords start hidden, a tick away in Columns (case-files-list-columns).
		expect(
			columns
				.filter((column) => column.hidden === true)
				.map((column) => column.key),
		).toEqual(['documentType', 'direction', 'partyName', 'keywords'])
		expect(
			normaliseFileColumns(filesProps().columns)[0].label,
			'board DqDocumentenBeheer heads the name column Document',
		).toBe('Document')

		// The rest of the row moved into the selected file's cards (DqDocumentenBeheer).
		const cardKeys = filesProps().sideCards.flatMap((card) =>
			card.fields.map((field) => field.key),
		)
		expect(cardKeys).toEqual(
			expect.arrayContaining([
				'senderName',
				'recipientNames',
				'approvalMarker',
				'integrity.value',
			]),
		)
	})

	it('reads the row columns from the dossier listing, keyed by file id', () => {
		const props = filesProps()

		expect(props.rowDataUrl).toBe('/apps/dossiq/api/cases/{objectId}/dossier')
		expect(props.rowDataPath).toBe('informatieobjecten')
		expect(props.rowDataKey).toBe('fileId')
	})

	/**
	 * The declared columns with the case type flag filled in, as the detail page
	 * resolves `@context.caseType.documentReview.enabled` in widget props.
	 *
	 * @param {boolean} reviews Whether the case type reviews its documents.
	 * @return {Array<object>} The normalised columns.
	 */
	function columnsFor(reviews) {
		const token = '@context.caseType.documentReview.enabled'
		const declared = withScanner(filesProps().columns).map((column) =>
			column.hidden === token ? { ...column, hidden: reviews } : column,
		)
		return normaliseFileColumns(declared)
	}

	// @spec openspec/specs/review-case-columns-hidden/spec.md#requirement-a-review-case-hides-scan-and-visible-to-by-default
	it('a review case starts without Scan and Visible to, which stay in Columns', () => {
		const review = columnsFor(true)

		expect(
			review
				.filter((column) => column.hidden !== true)
				.map((column) => column.key),
		).toEqual(['name'])
		expect(review.map((column) => column.key)).toEqual(
			expect.arrayContaining(['scanVerdict', 'vertrouwelijkheidaanduiding']),
		)
		expect(
			columnsFor(false)
				.filter((column) => column.hidden !== true)
				.map((column) => column.key),
		).toEqual(['name', 'scanVerdict', 'vertrouwelijkheidaanduiding'])
	})

	// @spec openspec/specs/scan-column-needs-a-scanner/spec.md#requirement-the-scan-column-shows-only-with-a-virus-scanner
	it('shows the Scan column only where the virus scanner files_antivirus is enabled', () => {
		const scan = filesProps().columns.find(
			(column) => column.key === 'scanVerdict',
		)

		expect(scan.requiredApp).toBe('files_antivirus')
		expect(
			filesProps()
				.columns.filter((column) => column.requiredApp !== undefined)
				.map((column) => column.key),
		).toEqual(['scanVerdict'])
	})

	it('control: the old declaration leaves the library nothing to render', () => {
		const old = [
			{ id: 'sender', label: 'Sender', property: 'senderName' },
			{ id: 'scan', label: 'Scan', property: 'scanVerdict' },
		]

		expect(normaliseFileColumns(old)).toEqual([])
	})
})
