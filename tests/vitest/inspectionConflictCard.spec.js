/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqInspectie, the sync frame: under the outbox, the open conflict reads
 * "Conflict: welke tekst houdt u?", the text on this device and the text at
 * the office, with "Deze houden" and "Die van kantoor". The buttons wait for
 * the resolve endpoint's capability, and the example set carries the board's
 * conflict with the field both sides changed.
 *
 * @spec openspec/changes/inspection-conflict-card/specs/mobiel-inspectie-offline/spec.md#requirement-conflict-detection-and-resolution-for-concurrent-edits
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
const conflictSchema = read('lib/Settings/register.d/40-mobiel-inspectie-offline.json').components.schemas.conflictRecord
const seed = read('lib/Settings/example-sets/zuiddrecht.d/64-inspection-conflict.json').objects[0]

describe('the inspection conflict card', () => {
	it('sits in the sync frame, under the outbox', () => {
		const frames = widget('inspection-frames').content.frames
		const sync = frames.find((frame) => frame.key === 'sync')
		expect(sync.widgetIds).toEqual(['inspection-outbox-offline-note', 'inspection-sync-queue', 'inspection-conflict'])
		expect(widget('inspection-conflict').type).toBe('conflict-resolve')
	})

	it('reads only the conflict records nobody resolved yet', () => {
		expect(widget('inspection-conflict').content.source).toEqual({
			register: 'dossiq',
			schema: 'conflictRecord',
			filter: { resolution: { isnull: true } },
			limit: 3,
		})
	})

	it.each([
		['title', 'Conflict: welke tekst houdt u?'],
		['keepLocalLabel', 'Deze houden'],
		['keepOfficeLabel', 'Die van kantoor'],
	])('says the board\'s %s in Dutch and English', (key, dutch) => {
		const text = widget('inspection-conflict').content[key]
		expect(en[text]).toBe(text)
		expect(nl[text]).toBe(dutch)
	})

	it('fills the board\'s two lines from the seeded conflict', () => {
		const { content } = widget('inspection-conflict')
		const [field] = seed.data.conflictFields
		const at = (version) => field.split('.').reduce((value, key) => value[key], seed.data[version])
		expect(nl[content.localText].replace('{value}', at('clientVersion'))).toBe('Op dit toestel: "Bijgebouw 4 bij 6 meter, in de groenstrook."')
		expect(nl[content.officeText].replace('{value}', at('serverVersion'))).toBe('Op kantoor: "Bijgebouw circa 24 m², zonder vergunning."')
	})

	it('keeps the buttons off until dossiq announces the resolve endpoint', () => {
		const { resolve, unavailableText } = widget('inspection-conflict').content
		expect(resolve).toEqual({ url: '/apps/dossiq/api/conflict-records/{id}/resolve', capability: 'dossiq.conflictResolution' })
		expect(nl[unavailableText]).toBe('Een tekst kiezen kan nog niet. Uw wijziging blijft in de wachtrij.')
	})

	it('declares every field the card and the device write on a conflict record', () => {
		for (const key of ['syncQueueRef', 'clientVersion', 'serverVersion', 'resolution', 'targetRegister', 'targetSchema', 'targetId', 'conflictFields', 'detectedAt']) {
			expect(conflictSchema.properties[key], key).toBeDefined()
		}
		for (const key of Object.keys(seed.data)) {
			expect(conflictSchema.properties[key], key).toBeDefined()
		}
	})

	it('links the seeded conflict to the outbox row that met it', () => {
		expect(seed.data.syncQueueRef).toBe('{{object:outbox-molenweg-bevinding.id}}')
		expect(seed.data.resolution).toBeUndefined()
	})
})
