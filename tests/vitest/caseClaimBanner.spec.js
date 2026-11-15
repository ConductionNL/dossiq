// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A member of the case's group who is not its handler reads the case and sees
 * one banner with one button: add yourself as handler to be able to edit.
 *
 * The page asks OpenRegister for the record's rights (`config.permissions`), so
 * the edit controls follow `@self.can.update`. The banner shows while
 * `can.update` is false, and its button runs the declared action named in ONE
 * place, the action's `declaredAction`. The library shows the button, and the
 * banner, only while `@self.can` grants that action, so a reader who may not
 * claim the case sees nothing.
 *
 * @spec openspec/changes/claim-to-edit-banner/specs/case-management/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (file) => JSON.parse(fs.readFileSync(path.join(__dirname, '../..', file), 'utf8'))
const manifest = read('src/manifest.json')
const en = read('l10n/en.json').translations
const nl = read('l10n/nl.json').translations
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const widget = (id) => caseDetail.config.widgets.find((entry) => entry.id === id)

const TEXT = 'Add yourself as handler to be able to edit.'
const BUTTON = 'Add me as handler'
const DONE = 'You are now handling this case.'

describe('the claim-to-edit banner on the case page', () => {
	it('reads the record rights, so the edit controls follow can.update', () => {
		expect(caseDetail.config.permissions).toBe(true)
	})

	it('shows while the reader may not update the case', () => {
		const banner = widget('case-claim-banner')
		expect(banner.type).toBe('banner')
		expect(banner.content.layout).toBe('attention')
		expect(banner.content.title).toBe(TEXT)
		expect(banner.content.visibleWhen).toEqual({ field: '@self.can.update', op: 'eq', value: false })
	})

	it('offers one button that runs the declared claim action', () => {
		const actions = widget('case-claim-banner').content.actions
		expect(actions).toHaveLength(1)
		expect(actions[0]).toMatchObject({ label: BUTTON, declaredAction: 'claim-as-handler', successMessage: DONE })
	})

	it('sits in the banner row', () => {
		expect(widget('case-banner-stack').content.sections.map((section) => section.widgetId)).toContain('case-claim-banner')
	})

	it('says it in English and Dutch', () => {
		expect(en[TEXT]).toBe(TEXT)
		expect(nl[TEXT]).toBe('Voeg jezelf toe als behandelaar om te kunnen bewerken.')
		expect(en[BUTTON]).toBe(BUTTON)
		expect(nl[BUTTON]).toBe('Voeg mij toe als behandelaar')
		expect(en[DONE]).toBe(DONE)
		expect(nl[DONE]).toBe('Je behandelt deze zaak nu.')
	})
})
