/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The simple profile's task list says what board DqTaken says: the search
 * field reads "Zoek op taak of zaaknummer" and the due column "Deadline".
 *
 * @spec openspec/specs/index-list-labels/spec.md#requirement-list-search-fields-and-columns-read-as-the-boards
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

const tasks = readJson('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'Tasks').config
const nl = readJson('l10n/nl.json').translations
const en = readJson('l10n/en.json').translations

describe('simple profile task list (DqTaken)', () => {
	it('reads "Zoek op taak of zaaknummer" in the search field', () => {
		expect(nl[tasks.searchPlaceholder]).toBe('Zoek op taak of zaaknummer')
		expect(en[tasks.searchPlaceholder]).toBe('Search by task or case number')
	})

	it('heads the due column "Deadline"', () => {
		const due = tasks.columns.find((column) => column.key === 'dueAt')
		expect(nl[due.label]).toBe('Deadline')
	})
})
