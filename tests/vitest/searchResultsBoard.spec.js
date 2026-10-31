/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The search page as DqZoekresultaten draws it: the kind chips share one row
 * with the Filter button and the view switch, and no row of saved-view chips
 * sits under them (the saved search shows in its side card).
 *
 * @spec openspec/specs/semantic-case-intake/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const page = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.d', '89-search.json'), 'utf8')).pages[0]

describe('DqZoekresultaten', () => {
	it('puts the kind chips on the toolbar row', () => {
		expect(page.config.quickFilterPlacement).toBe('toolbar')
	})

	it('draws no saved-view chips, and keeps saved searches with their side card', () => {
		expect(page.config.savedViewChips).toBe(false)
		expect(page.config.allowSavedViews).toBe(true)
		expect(page.config.sidePanel.map((card) => card.id)).toEqual(['search-saved', 'search-exports'])
	})

	it('names each row by what it is, as plain text (nextcloud-vue search-kind-as-text)', () => {
		const source = page.config.sourceConfig
		expect(source.kindAsText).toBe(true)
		expect(source.kinds.map((kind) => [kind.id, kind.rowLabel])).toEqual([
			['cases', 'Case'],
			['objects', '{objectType}'],
			['documents', 'Document'],
		])
	})

	it('words the line under a case as the board does, "Zaak 2026-0082" (nextcloud-vue search-subtitle-template)', () => {
		const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
		const cases = page.config.sourceConfig.kinds.find((kind) => kind.id === 'cases')
		expect(cases.subtitleTemplate.startsWith('Case {identifier}')).toBe(true)
		expect(nl[cases.subtitleTemplate].startsWith('Zaak {identifier}')).toBe(true)
		expect(cases.subtitleField).toBe('identifier')
	})

	it('names the handler and gives Resultaat room, as the board does (nextcloud-vue search-linked-user-and-widths)', () => {
		const source = page.config.sourceConfig
		expect(source.kinds.find((kind) => kind.id === 'cases').linkedUser).toBe(true)
		expect(source.columnWidths).toEqual({ title: '275px', kindText: '111px', linked: '145px' })
	})

	it('colours the status pill as the case list does (r29: In behandeling purple, Ontvangen blue, Afgehandeld green)', () => {
		expect(page.config.sourceConfig.statusColorMap).toMatchObject({
			'In behandeling': 'purple',
			Ontvangen: 'info',
			Afgehandeld: 'success',
			Besluit: 'teal',
			'Wacht op inwoner': 'warning',
		})
	})

	it('names the case type under a found case and wraps long titles (r29, nextcloud-vue search-subtitle-resolve-and-wrap)', () => {
		const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
		const cases = page.config.sourceConfig.kinds.find((kind) => kind.id === 'cases')
		expect(cases.subtitleTemplate).toBe('Case {identifier} · {caseTypeTitle}')
		expect(nl[cases.subtitleTemplate]).toBe('Zaak {identifier} · {caseTypeTitle}')
		expect(cases.subtitleResolve).toEqual({ field: 'caseType', schema: 'caseType', labelField: 'title', as: 'caseTypeTitle' })
		expect(page.config.sourceConfig.titleWrap).toBe(true)
	})
})
