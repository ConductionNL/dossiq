/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakEmail: one card "E-mail" with Mail koppelen and Mail opstellen in its
 * header, the template row, and the mail linked to the case listed in the
 * same card; the notes stand under it.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')
const tab = simple.configPatch.widgets['case-panels'].content.tabs.find((entry) => entry.label === 'Email')
const mail = simple.configPatch.widgets['case-email-panel'].content
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const notesWidget = manifest.pages.find((page) => page.id === 'CaseDetail').config.widgets.find((widget) => widget.id === 'case-notes-panel')

describe('the Email tab', () => {
	it('is the mail card, then the notes', () => {
		expect(tab.widgetIds).toEqual(['case-email-panel', 'case-notes-panel'])
	})

	it('lists the linked mail in the mail card, with link and compose in its header', () => {
		expect(mail.linkedMail).toBe(true)
		expect(mail.headerActions).toEqual(['link', 'compose'])
	})

	it('keeps "Leeg concept" in the template row beside "Mail opstellen"', () => {
		expect(mail.draft.blankButton).toBe(true)
	})
})

describe('the notes in the Email tab', () => {
	it('stand in a card of their own, titled and subtitled as the board draws "Notities"', () => {
		const notes = simple.configPatch.widgets['case-notes-panel']
		expect(notes.content.view).toBe('tab')
	})

	it('draws each note led by its author\'s initials, the text bold and "author · time" under it', () => {
		expect(notesWidget.props.noteRows).toBe('authored')
	})
})
