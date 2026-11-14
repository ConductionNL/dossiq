/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case Files tab carries what the retired Documents tab had and the folder
 * lacked: the document's Direction and Keywords as row columns, both starting
 * hidden in the Columns chooser, and a Keywords facet.
 *
 * @spec openspec/changes/documents-on-the-case/specs/document-zaakdossier/spec.md
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
const column = (key) => widget.props.columns.find((entry) => entry.key === key)

describe('the case files list carries direction and keywords', () => {
	it('declares direction and keywords as hidden row columns', () => {
		for (const key of ['direction', 'keywords']) {
			expect(column(key)).toMatchObject({
				source: 'row',
				property: key,
				hidden: true,
			})
		}
		expect(column('keywords').label).toBe('Keywords')
		expect(column('direction').label).toBe('Direction')
	})

	it('offers a facet on the keywords', () => {
		expect(widget.props.facets).toContain('keywords')
	})

	it('labels the keywords column in Dutch', () => {
		expect(nl[column('keywords').label]).toBe('Trefwoorden')
	})
})
