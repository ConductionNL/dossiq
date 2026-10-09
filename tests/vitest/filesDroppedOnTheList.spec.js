/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case Files tab asks the files browser for the board's Add files button,
 * the drop state on the list and the hint under it (DqZaakDocumenten,
 * DqZaakDocumentenSlepen). The words are the library's, so the manifest names
 * none: a label declared here would reach the component untranslated.
 *
 * @spec openspec/changes/files-dropped-on-the-list/specs/case-dashboard-view/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))

/**
 * Find a widget by id anywhere in the manifest.
 *
 * @param {unknown} node Where to look.
 * @param {string} id The widget id.
 * @return {object|null} The widget.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const found = findWidget(item, id)
			if (found) {
				return found
			}
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === id && node.type === 'integration') {
			return node
		}
		for (const value of Object.values(node)) {
			const found = findWidget(value, id)
			if (found) {
				return found
			}
		}
	}
	return null
}

describe('files dropped on the list', () => {
	const widget = findWidget(manifest, 'case-files')

	it('is the files integration on the case page', () => {
		expect(widget).not.toBeNull()
		expect(widget.integrationId).toBe('files')
	})

	it('asks for the button, the drop state and the hint', () => {
		expect(widget.props).toMatchObject({ uploadButton: true, dropOverlay: true, dropHint: true })
	})

	it('names no label of its own, so the Dutch comes from the library', () => {
		for (const key of ['uploadButtonLabel', 'dropLabel', 'dropHintLabel']) {
			expect(widget.props[key], key).toBeUndefined()
		}
	})
})
