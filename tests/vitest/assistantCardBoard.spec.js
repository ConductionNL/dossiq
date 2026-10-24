/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakAssistent: the assistant beside the AI oversight tab is a card
 * titled Assistant with a Hermiq pill, a line on what it sees and an Ask
 * button (nextcloud-vue ai-chat in a titled card).
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const chat = simple.configAppend.widgets.find((widget) => widget.id === 'case-assistant-chat')
const nl = read('l10n/nl.json').translations

describe('the assistant card', () => {
	it('names the assistant, says what it sees and asks', () => {
		expect(chat.title).toBe('Assistant')
		expect(chat.content).toMatchObject({
			chatAppId: 'hermiq',
			pill: 'Hermiq',
			note: 'The assistant sees only what you see on this case. Without Hermiq this panel is off.',
			placeholder: 'Ask something about this case',
			sendLabel: 'Ask',
		})
	})

	it('reads as the board in Dutch', () => {
		expect(nl[chat.title]).toBe('Assistent')
		expect(nl[chat.content.note]).toBe('De assistent ziet alleen wat u op deze zaak ziet. Is Hermiq er niet, dan staat dit paneel uit.')
		expect(nl[chat.content.placeholder]).toBe('Vraag iets over deze zaak')
		expect(nl[chat.content.sendLabel]).toBe('Vragen')
	})
})
