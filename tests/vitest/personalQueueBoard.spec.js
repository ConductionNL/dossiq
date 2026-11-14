/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The personal queue page as DqUwWachtrij draws it: the header carries
 * "Iets inplannen" and the buildiq square, no Actions menu, and the
 * planning dialog addresses the reader as "u", as every Dq board does.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const queue = readJson('src', 'manifest.json').pages.find((page) => page.id === 'PersonalQueue')
const nl = readJson('l10n', 'nl.json').translations
const nlJs = fs.readFileSync(path.join(ROOT, 'l10n', 'nl.js'), 'utf8')

describe('DqUwWachtrij', () => {
	it('shows no Actions menu in the header', () => {
		expect(queue.config.showActionsMenu).toBe(false)
	})

	it('keeps Plan an item in the header', () => {
		expect(queue.config.headerActions.map((action) => action.id)).toEqual(['plan-item'])
	})

	it('says "u" in the planning dialog', () => {
		const subtitle = queue.config.headerActions[0].props.config.subtitle
		expect(nl[subtitle]).toBe('Dit komt in uw eigen agenda en in uw wachtrij. Het is geen zaak.')
		expect(nlJs).toContain(`"${subtitle}": "Dit komt in uw eigen agenda en in uw wachtrij. Het is geen zaak."`)
	})

	it('asks for a template as "Sjabloon (niet verplicht)" over "Kies een sjabloon (optioneel)"', () => {
		const template = queue.config.headerActions[0].props.config.fields.find((field) => field.field === 'template')
		expect(template).toMatchObject({ type: 'select', label: 'Template', required: false, placeholder: 'Choose a template (optional)' })
		expect(nl[template.label]).toBe('Sjabloon')
		expect(nl[template.placeholder]).toBe('Kies een sjabloon (optioneel)')
	})
})
