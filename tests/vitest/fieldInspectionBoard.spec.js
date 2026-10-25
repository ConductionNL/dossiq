/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqInspectie: the day's inspections read "09:30 · <checklist>" with the
 * state as a toned word (Klaar green, Bezig blue, Gepland neutral), the outbox
 * says Wacht (amber) or Conflict (red), and the running checklist opens at its
 * first open question.
 *
 * @spec openspec/changes/screens-round12-field-inspection/tasks.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const en = read('l10n/en.json').translations
const page = read('src/manifest.d/72-field-inspection.json').pages[0]
const widget = (id) => page.config.widgets.find((entry) => entry.id === id)

describe('the field inspection board', () => {
	it('reads each inspection of today as its time and checklist', () => {
		const today = widget('inspections-today').content
		expect(today.meta).toBe('{scheduledAt|time} · {checklistTemplateRef.name}')
		expect(today.extend).toEqual(['caseRef', 'checklistTemplateRef'])
	})

	it.each([
		['inspections-today', { synced: ['Klaar', 'success'], in_progress: ['Bezig', 'info'], planned: ['Gepland', 'default'], conflict: ['Conflict', 'error'] }],
		['inspection-sync-queue', { pending: ['Wacht', 'warning'], syncing: ['Bezig', 'info'], conflict: ['Conflict', 'error'], failed: ['Mislukt', 'error'] }],
	])('names the states of %s in words, toned as the board', (id, states) => {
		const { pill } = widget(id).content
		for (const [value, [word, tone]] of Object.entries(states)) {
			expect(en[pill.labels[value]]).toBe(pill.labels[value])
			expect(nl[pill.labels[value]]).toBe(word)
			expect(pill.colorMap[value] ?? 'default').toBe(tone)
		}
	})

	it('names each outbox row by its label, with the technical name as the fallback', () => {
		expect(widget('inspection-sync-queue').content.title).toEqual(['{label}', '{targetEntity}'])
	})

	it('draws the outbox as cards, oldest first, each saying what it holds', () => {
		const { content } = widget('inspection-sync-queue')
		expect(content.presentation).toBe('cards')
		expect(content.sort).toEqual({ field: 'queuedAt', dir: 'asc' })
		expect(content.meta).toEqual(['{summary}', '{lastError}', '{operationType} · {queuedAt|datetime}'])
	})

	it('draws today\'s inspections as cards named by their location, else the case', () => {
		const { content } = widget('inspections-today')
		expect(content.presentation).toBe('cards')
		expect(content.title).toEqual(['{location}', '{caseRef.title}'])
	})

	it('opens the running checklist at its first open question', () => {
		expect(widget('checklist-run').content.startAt).toBe('firstOpen')
	})
})
