/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTaak's Zaak card reads "Behandelaar Pieter Jansen · termijn van de zaak
 * 9 okt": the case's term mid-line, in lower case, as part of the sentence.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const task = read('src/manifest.json').pages.find((page) => page.id === 'TaskDetail').config
const card = task.sideColumn.find((entry) => typeof entry === 'object' && entry.id === 'side-case')
const nl = read('l10n/nl.json').translations

describe('the task\'s Zaak card owner line (DqTaak)', () => {
	it('names the case\'s term as the board writes it', () => {
		const owner = card.content.fields.find((field) => field.key === 'case-owner')
		const term = owner.parts.find((part) => part.path === 'deadline')
		expect(nl[term.label]).toBe('termijn van de zaak')
	})
})
