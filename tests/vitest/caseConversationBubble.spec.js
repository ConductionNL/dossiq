/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakContact "Gesprek met de inwoner": the requester's name on an incoming
 * message, the handler who logged an outgoing one by name, "Inkomend" before
 * the time, channel chips, "Contact vastleggen" beside the title and a line
 * under it.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const thread = simple.configAppend.widgets.find((widget) => widget.id === 'case-resident-thread').content
const nl = read('l10n/nl.json').translations

describe('the conversation with the resident', () => {
	it('names who wrote each message', () => {
		expect(thread.usAuthorField).toBe('kccEmployeeId')
		expect(thread.usAuthorFormat).toBe('user')
		expect(thread.themAuthorField).toBe('@object.initiatorDisplayName')
	})

	it('says the direction, draws channel chips and a line under the title', () => {
		expect(thread.showDirection).toBe(true)
		expect(thread.channelChips).toBe(true)
		expect(nl[thread.subtitle]).toBe('Oudste bovenaan, met het kanaal per bericht')
	})

	it('logs a contact from beside the title, with the page action\'s own dialog', () => {
		expect(thread.headerAction).toMatchObject({ label: 'Log contact', type: 'open-modal', target: 'LogContactDialog' })
		expect(nl['Log contact']).toBe('Contact vastleggen')
	})
})
