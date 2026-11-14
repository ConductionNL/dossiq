// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakHistorie: "Notitie toevoegen" is a card of its own under the
 * history card, and the history card keeps Add note without a form.
 *
 * @spec openspec/changes/history-note-card/specs/case-page/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d')).filter((name) => name.endsWith('.json')).sort().map((name) => readJson('src', 'manifest.d', name))
const simple = buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, readJson('src', 'menu-layout.simple.json')).pages.find((page) => page.id === 'CaseDetail').config
const widget = (id) => simple.widgets.find((entry) => entry.id === id)
const nl = readJson('l10n', 'nl.json').translations

describe('DqZaakHistorie note card', () => {
	it('stacks the note card under the history card in the history tab', () => {
		const tab = widget('case-panels').content.tabs.find((entry) => entry.label === 'Case history')
		expect(tab.widgetIds).toEqual(['case-timeline-panel', 'case-note-card'])
	})

	it('draws the history without a form and the note card as the form alone', () => {
		expect(widget('case-timeline-panel').content).toMatchObject({ manage: true, composer: 'separate', exportName: 'case-history', visibilityLabel: false, readsToggle: 'Also who read the case' })
		expect(nl['Also who read the case']).toBe('Ook wie de zaak las')
		expect(widget('case-timeline-panel').content.entryActions.map((action) => action.id)).toEqual(['reply'])
		expect(widget('case-note-card')).toMatchObject({ type: 'timeline', title: 'Add note', content: { manage: true, composer: 'only' } })
		expect(nl['Add note']).toBe('Notitie toevoegen')
		expect(nl[widget('case-note-card').subtitle]).toBe('Komt op de tijdlijn hierboven, intern tenzij u hem zichtbaar maakt')
	})

	it('gives the note card its title in content, where the self-titled form card reads it', () => {
		const note = widget('case-note-card')
		expect(note.content.title).toBe(note.title)
		expect(note.content.subtitle).toBe(note.subtitle)
	})
})
