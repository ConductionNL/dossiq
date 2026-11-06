/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakDocumenten draws the cited ground under a partly or not public verdict
 * ("Deels openbaar" over "grond 5.1.2.e"). The Assessment column of the case
 * files declares it as a nextcloud-vue column `subline` over the dossier
 * listing's `reviewGrounds`, so a verdict without a cited ground draws the
 * pill alone.
 *
 * @spec openspec/changes/verdict-row-cites-its-ground/specs/verdict-row-cites-its-ground/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

/**
 * Every object in a JSON tree that satisfies the test.
 *
 * @param {unknown} node The tree.
 * @param {(o: object) => boolean} test The test.
 * @param {Array<object>} found The matches so far.
 * @return {Array<object>} The matches.
 */
function findAll(node, test, found = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => findAll(item, test, found))
	} else if (node && typeof node === 'object') {
		if (test(node)) {
			found.push(node)
		}
		Object.values(node).forEach((value) => findAll(value, test, found))
	}
	return found
}

describe('the case files Assessment column cites the ground', () => {
	const [column] = findAll(readJson('src', 'manifest.json'), (o) => o.id === 'review-verdict' && o.key === 'reviewVerdict')

	it('declares the ground line under the verdict', () => {
		expect(column.subline).toBe('ground {reviewGrounds|values}')
	})

	it('translates the ground line', () => {
		expect(readJson('l10n', 'en.json').translations['ground {reviewGrounds|values}']).toBe('ground {reviewGrounds|values}')
		expect(readJson('l10n', 'nl.json').translations['ground {reviewGrounds|values}']).toBe('grond {reviewGrounds|values}')
	})
})
