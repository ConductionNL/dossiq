/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type page draws no Follow toggle (boards DqZaaktypeOverzicht,
 * DqZaaktype, DqZaaktypeVelden).
 *
 * @spec openspec/specs/case-type-page-no-follow/spec.md#requirement-the-case-type-page-has-no-follow-control
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {string} file Path under the repository root.
 * @return {object} The parsed document.
 */
function readJson(file) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
}

describe('case type page follow control', () => {
	it('is turned off on the page', () => {
		const page = readJson('src/manifest.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
		expect(page.config.follow).toBe(false)
	})

	it.each(['src/menu-layout.json', 'src/menu-layout.simple.json'])('is not turned back on by %s', (file) => {
		const overlay = (readJson(file).pages || []).find((entry) => entry.id === 'CaseTypeDetail')
		expect(overlay?.config?.follow ?? false).toBe(false)
	})
})
