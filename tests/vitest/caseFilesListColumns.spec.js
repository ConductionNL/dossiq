/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case Files tab declares the document columns of board DqZaakDocumenten
 * as `row` columns over the dossier listing, read once per folder through
 * `rowDataUrl`. Document type, direction and sender or recipient sit in the
 * Columns chooser; the assessment shows only on a case type that reviews its
 * documents.
 *
 * @spec openspec/changes/case-files-list-columns/specs/document-zaakdossier/spec.md#requirement-the-case-files-list-shows-the-document-columns-of-the-board-req-zak-col-01
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

/**
 * The first object in a tree that satisfies a test, depth first.
 *
 * @param {*} node The tree.
 * @param {(value: object) => boolean} test The test.
 * @return {object|null} The match.
 */
function findIn(node, test) {
	if (!node || typeof node !== 'object') {
		return null
	}
	if (!Array.isArray(node) && test(node)) {
		return node
	}
	for (const value of Object.values(node)) {
		const hit = findIn(value, test)
		if (hit) {
			return hit
		}
	}
	return null
}

const manifest = read('src/manifest.json')
const nl = read('l10n/nl.json').translations
const widget = findIn(
	manifest,
	(value) => value.id === 'case-files' && value.integrationId === 'files',
)
const columns = widget.props.columns
const column = (key) => columns.find((entry) => entry.key === key)

describe('the case files list columns', () => {
	it('reads every row column from the dossier listing, keyed by file id', () => {
		expect(widget.props.rowDataUrl).toBe(
			'/apps/dossiq/api/cases/{objectId}/dossier',
		)
		expect(widget.props.rowDataPath).toBe('informatieobjecten')
		expect(widget.props.rowDataKey).toBe('fileId')
	})

	it('declares document type, direction and sender or recipient as row columns in the chooser', () => {
		for (const key of ['documentType', 'direction', 'partyName']) {
			expect(column(key)).toMatchObject({
				source: 'row',
				property: key,
				hidden: true,
			})
		}
		const order = columns.map((entry) => entry.key)
		expect(order.indexOf('documentType')).toBeLessThan(
			order.indexOf('direction'),
		)
		expect(order.indexOf('direction')).toBeLessThan(order.indexOf('partyName'))
	})

	it('shows the assessment only on a case type that reviews its documents', () => {
		expect(column('reviewVerdict').when).toBe(
			'@context.caseType.documentReview.enabled',
		)
	})

	it('labels the new columns in Dutch as the board does', () => {
		expect(nl[column('documentType').label]).toBe('Documenttype')
		expect(nl[column('direction').label]).toBe('Richting')
		expect(nl[column('partyName').label]).toBe('Afzender of ontvanger')
	})
})
