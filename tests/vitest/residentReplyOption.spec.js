/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakContact's reply box says the answer goes to the portal message box and
 * offers "Vastleggen als contactmoment op deze zaak"; the switch's state
 * reaches the portal message dialog, which logs the moment.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const thread = simple.configAppend.widgets.find((widget) => widget.id === 'case-resident-thread').content
const nl = read('l10n/nl.json').translations

describe('the reply to the resident', () => {
	it('says where it goes', () => {
		expect(nl[thread.replyHint]).toBe('Gaat naar de berichtenbox in het portaal')
	})

	it('offers to log it as a contact moment, on by default, under the dialog\'s own prop', () => {
		expect(thread.replyOption).toEqual({ label: 'Log as a contact moment on this case', key: 'logContactMoment', value: true })
		expect(nl[thread.replyOption.label]).toBe('Vastleggen als contactmoment op deze zaak')
		expect(thread.replyAction.target).toBe('PortalMessageDialog')
	})
})
