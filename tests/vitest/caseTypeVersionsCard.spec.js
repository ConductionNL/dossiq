/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype Versies: Concept, Actief or Vastgezet per version; a published
 * version ends with a note and "Nieuwe versie maken", a draft with
 * "Publiceren".
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseTypeDetail')
const card = simple.config.sideColumn.find((widget) => widget.id === 'side-case-type-versions')
const nl = read('l10n/nl.json').translations

describe('the case type versions card', () => {
	it('labels a draft, a closed version and the version in force', () => {
		const pill = card.content.pill
		expect(pill.cases).toEqual([
			{ when: { field: 'isDraft', op: 'eq', value: true }, label: 'Draft', variant: 'warning' },
			{ when: { field: 'validUntil', op: 'notEmpty' }, label: 'Frozen', variant: 'default' },
		])
		expect(pill.label).toBe('Active')
		expect(pill.variant).toBe('success')
		expect([nl.Draft, nl.Frozen, nl.Active]).toEqual(['Concept', 'Vastgezet', 'Actief'])
	})

	it('offers Publish on a draft and a new version otherwise', () => {
		const [publish, newVersion] = card.content.footerAction
		expect(publish).toMatchObject({ label: 'Publish', type: 'open-modal', target: 'CaseTypePublishDialog', visibleWhen: { field: 'isDraft', op: 'eq', value: true } })
		expect(newVersion).toMatchObject({ label: 'Make a new version', type: 'open-modal', target: 'CaseTypeNewVersionDialog', wide: true })
		expect(newVersion.visibleWhen).toBeUndefined()
		expect(nl[newVersion.label]).toBe('Nieuwe versie maken')
		expect(nl[newVersion.note]).toBe('Een gepubliceerde versie verandert niet meer. Wijzigen maakt een nieuwe versie.')
	})
})
