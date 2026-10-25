/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * documents-on-the-case 2.2 and 3.3, on the Files tab: a document's Direction
 * and Keywords are row columns that start hidden (the board draws the tab
 * without them; they are a tick away in Columns), Keywords filters, and the
 * New menu offers Generate document.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))

/**
 * The widget with this id under a node.
 *
 * @param {object} node The manifest or a part of it.
 * @param {string} id The widget id.
 * @return {object|undefined} The widget.
 */
function widget(node, id) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const hit = widget(item, id)
			if (hit) {
				return hit
			}
		}
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
			return node
		}
		for (const value of Object.values(node)) {
			const hit = widget(value, id)
			if (hit) {
				return hit
			}
		}
	}
	return undefined
}

const files = widget(manifest.pages.find((page) => page.id === 'CaseDetail'), 'case-files').props

describe('the Files tab keeps the documents list\'s metadata', () => {
	it('declares Direction and Keywords from the row, hidden until ticked, in a Columns chooser it remembers', () => {
		const direction = files.columns.find((column) => column.key === 'direction')
		const keywords = files.columns.find((column) => column.key === 'keywords')
		expect(direction).toMatchObject({ source: 'row', property: 'direction', formatter: 'documentDirection', hidden: true })
		expect(keywords).toMatchObject({ source: 'row', property: 'keywords', hidden: true })
		expect(files.preferenceApp).toBe('dossiq')
	})

	it('filters on Keywords', () => {
		expect(files.facets).toEqual(['keywords'])
	})

	it('leaves Generate document to the case header, which opens the same dialog for this case', () => {
		expect((files.newActions ?? []).some((action) => action.id === 'generate-document')).toBe(false)
		const header = manifest.pages.find((page) => page.id === 'CaseDetail').config.headerActions
		const entry = header.find((action) => action.id === 'generate-document')
		expect(entry).toMatchObject({ type: 'open-modal', target: 'BeschikkingComposerDialog', props: { caseId: '@objectId' } })
	})

	it('labels a direction', async () => {
		const { default: formatters } = await import('../../src/services/formatters.js')
		expect(formatters.documentDirection('incoming')).toBe('Incoming')
		expect(formatters.documentDirection('')).toBe('')
	})
})
