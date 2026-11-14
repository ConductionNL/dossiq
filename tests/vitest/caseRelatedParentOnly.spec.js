/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakGerelateerd draws no "Hoofdzaak" card on a case without a parent case,
 * and puts "Zaak koppelen" in the header of Gerelateerde zaken. The parent-case
 * card shows only when the case has a parent, and only that parent.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const nl = read('l10n/nl.json').translations
const card = (id) => simple.configAppend.widgets.find((widget) => widget.id === id)

describe('the parent case card', () => {
	const parent = card('case-related-card')

	it('shows only on a case with a parent case', () => {
		expect(parent.visibleWhen).toEqual({ field: 'parentCase', op: 'notEmpty' })
	})

	it('shows the parent and nothing else', () => {
		expect(parent.content.showObjects).toBe(false)
		expect(parent.content.showFiles).toBe(false)
		expect(parent.content.referenceSections.map((section) => section.key)).toEqual(['parent'])
		expect(parent.content.footerActions ?? []).toEqual([])
	})
})

describe('Gerelateerde zaken', () => {
	const related = card('case-related-cases-card')

	it('links a case from its header', () => {
		const [link] = related.headerActions
		expect(nl[link.label]).toBe('Zaak koppelen')
		expect(link.action.type).toBe('open-modal')
		expect(link.action.target).toBe('CnActionDialog')
		expect(link.action.props.config.fields.map((field) => field.field)).toEqual(['targetId', 'aardRelatie', 'notes'])
	})
})
