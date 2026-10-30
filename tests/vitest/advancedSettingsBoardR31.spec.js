/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * r31 DqBeheerGeavanceerd: the AWB term definitions are one table, the
 * first-run titles read in the text ink, and a present badge is a light fill.
 *
 * @spec openspec/specs/admin-settings/spec.md
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

	it('draws a present prerequisite as the soft success tag', () => {
		expect(read('src', 'views', 'settings', 'tabs', 'PrerequisitesTab.vue')).toContain("'prerequisites__mark cn-status-badge cn-status-badge--success'")
	})
})

describe('the mandate matrix, r31', () => {
	it('picks its section with a labelled field, not a caption and chips', () => {
		const tab = read('src', 'views', 'settings', 'tabs', 'DelegationMatrixTab.vue')
		expect(tab).toContain('data-testid="mandaat-matrix-sections"')
		expect(tab).not.toContain('NcAppNavigationCaption')
		expect(tab).not.toContain('mandaat-matrix-tab__description')
	})

	it('names a mandate status in words', () => {
		const table = read('src', 'views', 'settings', 'components', 'DelegationMatrixTable.vue')
		expect(table).toContain("determined: t('dossiq', 'Adopted')")
		expect(table).not.toContain('variant="primary"')
	})

	it('draws a mandate status as the status badge in its tone', () => {
		const table = read('src', 'views', 'settings', 'components', 'DelegationMatrixTable.vue')
		expect(table).toContain('class="cn-status-badge"')
		expect(table).toContain("return 'cn-status-badge--success'")
		expect(table).not.toContain('mandaat-matrix-table__badge')
	})

	it('draws a first-run state as the board pill: soft ink, light fill, normal leading', () => {
		const tab = read('src', 'views', 'settings', 'tabs', 'FirstRunTab.vue')
		const rule = tab.slice(tab.indexOf('.first-run-tab__state {'), tab.indexOf('}', tab.indexOf('.first-run-tab__state {')))
		expect(rule).toContain('background: var(--color-background-hover);')
		expect(rule).toContain('line-height: normal;')
	})
})
