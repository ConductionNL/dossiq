/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTaak's history says what happened ("Taak opgepakt door Pieter Jansen"),
 * not the engine's raw `claim (active)`.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/manifest.json').pages.find((entry) => entry.id === 'TaskDetail')
const history = page.config.widgets.find((widget) => widget.id === 'task-history')
const sideHistory = page.config.sideColumn.find((widget) => widget.id === 'side-history')
const nl = read('l10n/nl.json').translations

describe('the task history', () => {
	it('words the engine\'s actions', () => {
		const labels = history.content.actionLabels
		for (const action of ['create', 'claim', 'unclaim', 'complete', 'cancel', 'terminate', 'delegate', 'assign', 'reassign', 'offer', 'enable']) {
			expect(labels[action], action).toBeTruthy()
		}
	})

	it('reads the board\'s Dutch', () => {
		const labels = history.content.actionLabels
		expect(nl[labels.claim]).toBe('Taak opgepakt door {actor}')
		expect(nl[labels.create]).toBe('Taak aangemaakt')
	})

	it('has a Dutch line for every label', () => {
		for (const label of Object.values(history.content.actionLabels)) {
			expect(nl[label], label).toBeTruthy()
		}
	})

	it('words the side Historie card the same way as the history tab', () => {
		expect(sideHistory.type).toBe('audit-trail')
		expect(sideHistory.content.actionLabels).toEqual(history.content.actionLabels)
	})
})
