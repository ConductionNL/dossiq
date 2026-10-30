/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqFilterBeheren draws three default views with their lines: Mijn zaken
 * (Behandelaar is ik), Alle lopende zaken (Status niet afgehandeld) and
 * Termijn verstreken (Termijn voor vandaag, niet afgehandeld). Ruben (23 Oct):
 * those three first, every other default under "Meer standaardweergaven"
 * (`secondary`, nc-vue feat/r25-default-views-secondary). None is removed.
 *
 * @spec openspec/specs/default-views-board-first/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const cases = manifest.pages.find((page) => page.id === 'Cases' && page.config)
const views = cases.config.defaultSavedViews
const lens = (label) => cases.config.quickFilters.find((tab) => tab.label === label).filter

describe('the case list default views', () => {
	it('lists the board\'s three first, with their lines in Dutch', () => {
		const first = views.slice(0, 3).map((v) => [nl[v.name], nl[v.description]])
		expect(first).toEqual([
			['Mijn zaken', 'Behandelaar is ik'],
			['Alle lopende zaken', 'Status niet afgehandeld'],
			['Termijn verstreken', 'Termijn voor vandaag, niet afgehandeld'],
		])
		expect(views.slice(0, 3).some((v) => v.secondary)).toBe(false)
	})

	it('keeps every other default, folded under More default views', () => {
		expect(views.length).toBeGreaterThan(3)
		expect(views.slice(3).every((v) => v.secondary === true)).toBe(true)
	})

	it('filters the three as the list\'s own lenses do', () => {
		expect(views[0].filters).toEqual(lens('Mine'))
		expect(views[2].filters).toEqual(lens('Overdue'))
		expect(views[1].filters).toEqual({ isFinalStatus: false, statusHiddenInLists: false, isDraft: false })
	})

	it('gives every default view an icon the app registers and a translated line (Ruben, 23 Oct)', () => {
		const icons = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
		for (const view of views) {
			expect(typeof view.icon).toBe('string')
			expect(icons).toMatch(new RegExp(`\\b${view.icon}\\b`))
			expect(nl[view.description]).toBeTruthy()
		}
	})
})
