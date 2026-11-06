/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqInstellingenAlgemeen "Werkdagen en feestdagen" draws Dag toevoegen in the
 * card head, beside the title. The section's actions open the calendar's own
 * Add day, and the calendar leaves its button out.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const admin = fs.readFileSync(path.join(root, 'src/views/settings/AdminRoot.vue'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(root, 'l10n/nl.json'), 'utf8')).translations

describe('the working days card head', () => {
	it('opens the calendar\'s Add day from the section actions', () => {
		expect(admin).toMatch(/<template #actions>\s*<NcButton[^>]*@click="\$refs\.workingCalendar\.openAdd\(\)">[\s\S]*?'Add day'/)
		expect(admin).toContain('<CnWorkingCalendarWidget ref="workingCalendar" :content="workingCalendarContent" />')
		expect(nl['Add day']).toBe('Dag toevoegen')
	})

	it('leaves the calendar\'s own Add day out', () => {
		expect(admin).toMatch(/workingCalendarContent\(\) \{[\s\S]*?addButton: false,/)
	})

	it('says what the red days mean under the calendar, not under the title', () => {
		const card = admin.slice(admin.indexOf('id="section-working-days"'), admin.indexOf('<CnWorkingCalendarWidget'))
		expect(card).not.toContain(':description=')
		const key = 'Red: a holiday or closing day. Terms do not count these days. One calendar for the whole organisation.'
		expect(admin).toMatch(/workingCalendarContent\(\) \{[\s\S]*?footnote: this\.t\(\s*'dossiq',\s*'Red: a holiday or closing day\. Terms do not count these days\. One calendar for the whole organisation\.',?\s*\)/)
		expect(nl[key]).toBe('Rood: feestdag of sluitingsdag. Termijnen tellen deze dagen niet mee. Een kalender voor de hele organisatie.')
	})
})
