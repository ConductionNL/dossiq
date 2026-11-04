/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqDashboard draws five signal tiles under the case tiles: Mijn open
 * werk, Wachtrij van uw teams (with Oppakken), Termijnsignalen,
 * Taakherinneringen and Woo-termijnen. Each is a library record list over a
 * query, configured in the simple structure's Dashboard overlay.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/signalering-widgets/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const simple = readJson('src', 'menu-layout.simple.json')
const overlay = simple.pages.find((entry) => entry.id === 'Dashboard')
const tiles = overlay.configAppend.widgets
const tile = (id) => tiles.find((widget) => widget.id === id)
const nl = readJson('l10n', 'nl.json').translations
const en = readJson('l10n', 'en.json').translations

const SIGNAL_TILES = ['my-open-work', 'teams-queue-signal', 'deadline-signals', 'task-reminders', 'woo-deadlines']
const OPEN = { isFinalStatus: false, statusHiddenInLists: false, isDraft: false }
const FLOW_TASKS = '/apps/openregister/api/flow-tasks'

describe('the dashboard signal tiles (DqDashboard)', () => {
	it('places the five tiles under the case tiles, two by two and Woo full width, above the layout note', () => {
		const layout = overlay.config.layout
		const placed = (id) => layout.find((entry) => entry.widgetId === id)
		const lowestCaseTile = Math.max(...['stalled-cases', 'favourite-cases', 'recent-cases'].map((id) => placed(id).gridY + placed(id).gridHeight))
		for (const id of SIGNAL_TILES) {
			expect(placed(id), id).toBeDefined()
			expect(placed(id).gridY, id).toBeGreaterThanOrEqual(lowestCaseTile)
			expect(placed(id).showTitle, id).toBe(true)
		}
		expect([placed('my-open-work').gridWidth, placed('teams-queue-signal').gridWidth]).toEqual([6, 6])
		expect(placed('my-open-work').gridY).toBe(placed('teams-queue-signal').gridY)
		expect(placed('deadline-signals').gridY).toBe(placed('task-reminders').gridY)
		expect(placed('woo-deadlines').gridWidth).toBe(12)
		const note = placed('layout-note')
		expect(note.gridY).toBeGreaterThanOrEqual(placed('woo-deadlines').gridY + placed('woo-deadlines').gridHeight)
	})

	it('draws every tile as library record-list rows with a title, a subtitle and a header link', () => {
		for (const id of SIGNAL_TILES) {
			const widget = tile(id)
			expect(widget.type, id).toBe('record-list')
			expect(widget.subtitle, id).toBeTruthy()
			expect(widget.headerLink.label, id).toBeTruthy()
			expect(widget.content.presentation, id).toBe('rows')
		}
	})

	it('lists the reader\'s own open cases and open tasks in My open work', () => {
		const [cases, tasks] = tile('my-open-work').content.sections
		expect(cases.filter).toEqual({ assignee: '@me', ...OPEN })
		expect(cases.sort).toEqual({ field: 'deadline', dir: 'asc' })
		expect(tasks.endpointSource.url).toBe(FLOW_TASKS)
		expect(tasks.endpointSource.params).toMatchObject({ scope: 'assigned', isTerminal: 'false', sort: 'dueAt' })
		expect(tasks.rowRoute).toBe('TaskDetail')
		expect(tile('my-open-work').headerLink.route).toBe('MyWorkHome')
	})

	it('reads the same team queue as My team, with Pick up through the claimCase handler', () => {
		const myWork = simple.pages.find((entry) => entry.id === 'MyWorkHome')
		const teamQueue = myWork.configPatch.views.team.widgets.find((widget) => widget.id === 'your-teams-queue')
		const content = tile('teams-queue-signal').content
		expect(content.filter).toEqual(teamQueue.content.filter)
		expect(content.rowAction).toEqual(teamQueue.content.rowAction)
		expect(content.rowAction.handler).toBe('claimCase')
		expect(tile('teams-queue-signal').headerLink.query).toEqual({ savedView: 'default:team-queue' })
	})

	it('splits the deadline signals into late cases and cases in the last quarter of their term', () => {
		const [late, risk] = tile('deadline-signals').content.sections
		expect(late.intro).toBe('Late')
		expect(late.filter).toEqual({ ...OPEN, deadline: { lt: '@today' } })
		expect(risk.intro).toBe('At risk')
		expect(risk.filter).toEqual({ ...OPEN, deadline: { gte: '@today' }, termSignalFrom: { lte: '@today' } })
		for (const section of [late, risk]) {
			expect(section.sort).toEqual({ field: 'deadline', dir: 'asc' })
			expect(section.due.field).toBe('deadline')
		}
	})

	it('reminds of the reader\'s late tasks and those due within three days', () => {
		const [late, soon] = tile('task-reminders').content.sections
		expect(late.endpointSource.url).toBe(FLOW_TASKS)
		expect(late.endpointSource.params).toMatchObject({ scope: 'assigned', isTerminal: 'false', dueBefore: '@today' })
		expect(soon.endpointSource.params).toMatchObject({ scope: 'assigned', isTerminal: 'false', dueAfter: '@today', dueBefore: '@today+4d' })
	})

	it('narrows the Woo deadlines on the Woo case type, as the Woo requests view does, soonest first', () => {
		const content = tile('woo-deadlines').content
		expect(content.filter).toEqual({ caseTypeIdentifier: 'woo-verzoek', ...OPEN })
		expect(content.sort).toEqual({ field: 'deadline', dir: 'asc' })
		expect(content.pill.cases.map((entry) => entry.variant)).toEqual(['error', 'warning', 'warning'])
		expect(content.pill.variant).toBe('success')
		expect(tile('woo-deadlines').headerLink.query).toEqual({ savedView: 'default:woo-requests' })
	})

	it('speaks the board\'s Dutch in every title and subtitle', () => {
		const words = {
			'my-open-work': ['Mijn open werk', 'Zaken en taken, eerst wat het eerst moet'],
			'teams-queue-signal': ['Wachtrij van uw teams', 'Opgepakt door niemand, in de teams waar u in zit'],
			'deadline-signals': ['Termijnsignalen', 'Te laat, en minder dan een kwart van de termijn over'],
			'task-reminders': ['Taakherinneringen', 'Taken die vandaag of binnen drie dagen moeten'],
			'woo-deadlines': ['Woo-termijnen', 'Alle lopende Woo-verzoeken op dagen tot de beslisdatum, minste eerst'],
		}
		for (const [id, [title, subtitle]] of Object.entries(words)) {
			expect(nl[tile(id).title], id).toBe(title)
			expect(nl[tile(id).subtitle], id).toBe(subtitle)
			expect(en[tile(id).title], id).toBe(tile(id).title)
		}
	})
})
