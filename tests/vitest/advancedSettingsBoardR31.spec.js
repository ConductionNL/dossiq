/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * r31 DqBeheerGeavanceerd: the AWB term definitions are one table, the
 * first-run titles read in the text ink, and a present badge is a light fill.
 *
 * @spec openspec/changes/deadline-definitions-board-table/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => fs.readFileSync(path.resolve(__dirname, '../..', ...parts), 'utf8')

describe('the advanced settings, r31', () => {
	it('draws the term definitions as one table with the board columns', () => {
		const tab = read('src', 'views', 'settings', 'tabs', 'DeadlineDefinitionsTab.vue')
		expect(tab).toContain('data-testid="deadline-definitions-table"')
		for (const head of ['Basis', 'Duration', 'Version', 'Counting', 'Closed day', 'Valid', 'State']) {
			expect(tab).toContain(`{{ t('dossiq', '${head}') }}`)
		}
		expect(tab).not.toContain('deadline-definitions-tab__description')
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		expect(nl.Basis).toBe('Grondslag')
		expect(nl['Closed day']).toBe('Gesloten dag')
	})

	it('reads the first-run titles in the text ink, not the link blue', () => {
		expect(read('src', 'views', 'settings', 'tabs', 'FirstRunTab.vue')).toContain('--nldesign-component-link-color: var(--color-main-text);')
	})

	it('draws a present prerequisite on a light fill', () => {
		expect(read('src', 'views', 'settings', 'tabs', 'PrerequisitesTab.vue')).toMatch(/__mark--present \{\s*background: color-mix\(/)
	})
})
