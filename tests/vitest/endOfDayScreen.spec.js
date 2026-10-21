// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * "Close out your day" is manifest config over library widgets
 * (end-of-day-manifest), and keeps what the custom screen held.
 *
 * Three silent failures are held here and none looks like one on screen.
 *
 * 🔴 "CHANGED TODAY" IS NOT "YOU TOUCHED IT". The lists come from the server,
 * which reads the register's per-reader read state (EndOfDayServiceTest holds
 * that); the page must read that endpoint and nothing that lists objects by
 * their own update time.
 *
 * 🔴 A DOSSIQ TIME FIELD WOULD LOOK LIKE THE HOURS LEAF. The only time box is
 * humaniq's leaf, per row, and nothing at all without humaniq.
 *
 * 🔴 A NOTE ON THE WRONG THING IS LOST. Each note goes to the row's own notes
 * endpoint, which the server names: a case's on the case, a task's on the task.
 *
 * @spec openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))
const page = manifest.pages.find((item) => item.id === 'EndOfDay')
const widget = (id) => page.config.widgets.find((item) => item.id === id)
const DAY = '/apps/dossiq/api/personal-queue/end-of-day'

describe('the end-of-day page', () => {
	it('is a manifest dashboard on the same route, without a custom view', () => {
		expect(page.type).toBe('dashboard')
		expect(page.route).toBe('/end-of-day')
		expect(page.component).toBeUndefined()
		expect(readFileSync(resolve(root, 'src/registry.js'), 'utf8')).not.toContain('EndOfDayView')
	})

	it('reads every list from the server\'s day, so Recorded today survives a reload', () => {
		const lists = page.config.widgets.filter((item) => item.type === 'record-list')
		expect(lists.map((item) => item.content.endpointSource.url)).toEqual([DAY, DAY, DAY, DAY])
		expect(lists.map((item) => item.content.endpointSource.responsePath)).toEqual(['open', 'recorded', 'figures', 'tomorrow'])
	})

	it('records each note on the row\'s own notes endpoint', () => {
		const open = widget('eod-open').content
		expect(open.presentation).toBe('notes')
		expect(open.noteAction.urlField).toBe('notesUrl')
		expect(open.noteAction.recordAll.label).toBe('Close out your day')
	})

	it('keeps typed notes in the browser as the ready figure inside the notes list', () => {
		const action = widget('eod-open').content.noteAction
		expect(action.draftsKey).toBe('dossiq-end-of-day')
		expect(action.readyLabel).toBe('filled in, not recorded yet')
	})

	it('places humaniq\'s hours leaf per row and offers no time field of its own', () => {
		const leaf = widget('eod-open').content.rowLeaf
		expect(leaf).toMatchObject({ id: 'humaniq-hours', app: 'humaniq', objectIdField: 'subjectId', schemaField: 'subjectType' })
		const text = JSON.stringify(page.config.widgets).toLowerCase()
		expect(text).not.toMatch(/"(hours|time)field"|"key":\s*"hours"/)
	})

	it('draws its icons from the ones the app registers', () => {
		const icons = readFileSync(resolve(root, 'src/icons.js'), 'utf8')
		expect(icons).toContain(`\n\t${widget('eod-recorded').content.icon},\n`)
		expect(icons).toContain(`\n\t${widget('eod-open').content.noteAction.recordAll.icon},\n`)
	})

	it('opens a case and a task on their own pages', () => {
		for (const id of ['eod-open', 'eod-recorded', 'eod-tomorrow']) {
			expect(widget(id).content.rowRouteField, id).toBe('routeName')
			expect(widget(id).content.rowIdField, id).toBe('routeId')
		}
	})

	it('says why the open list is empty, and hides First thing tomorrow when nothing is due', () => {
		expect(widget('eod-open').content.emptyTexts[0].when).toEqual({ endpoint: DAY, field: 'opened', op: 'eq', value: 0 })
		expect(widget('eod-tomorrow').visibleWhen).toEqual({ endpoint: DAY, field: 'tomorrow', op: 'notEmpty' })
	})
})
