/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The record boards' per-card buttons: "Flow uitvoeren" on the Flows card,
 * "Contact vastleggen" on the conversation with the resident, "Mail koppelen"
 * and "Mail opstellen" on the mail tab, the notes composer behind a button on
 * a task, and "Alle zaken op dit object" on every linked object. Each opens a
 * dialog or page dossiq already has.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const manifest = read('src/manifest.json')
const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const nl = read('l10n/nl.json').translations
const appended = (id) => simple.configAppend.widgets.find((widget) => widget.id === id)

/**
 * Whether the app registers a dialog under this name.
 *
 * @param {string} name The registry key.
 * @return {boolean}
 */
function registered(name) {
	return new RegExp(`\\b${name}\\b\\s*[:,]`).test(registry)
}

describe('per-card buttons on the record boards', () => {
	it('runs a flow from the Flows card, while the case has one to start', () => {
		const [run] = simple.configPatch.widgets['case-flow-runs'].headerActions
		expect(run.action).toEqual({ type: 'open-modal', target: 'CaseStartFlowDialog' })
		expect(run.visibleWhen.field).toBe('hasStartableFlows')
		expect(registered('CaseStartFlowDialog')).toBe(true)
		expect(nl[run.label]).toBe('Flow uitvoeren')
	})

	it('logs a contact from the conversation with the resident', () => {
		const [log] = appended('case-resident-thread').headerActions
		expect(log.action).toMatchObject({ type: 'open-modal', target: 'LogContactDialog', props: { caseId: '@objectId' } })
		expect(registered('LogContactDialog')).toBe(true)
		expect(nl[log.label]).toBe('Contact vastleggen')
	})

	it('offers Mail koppelen and Mail opstellen on the mail tab', () => {
		expect(simple.configPatch.widgets['case-email-panel'].content.headerActions).toEqual(['link', 'compose'])
	})

	it('keeps the task notes composer behind a button', () => {
		const task = manifest.pages.find((page) => page.id === 'TaskDetail')
		expect(task.config.widgets.find((widget) => widget.id === 'task-notes').props.composer).toBe('button')
	})

	it('lists every case on a linked object, by its address', () => {
		const [all] = appended('case-objects-card').content.rowActions
		expect(all).toMatchObject({ type: 'navigate', target: '/cases?_related[caseObject][case][objectUrl]=@row.objectUrl' })
		expect(all.visibleWhen).toEqual({ field: 'objectUrl', op: 'notEmpty' })
		expect(nl[all.label]).toBe('Alle zaken op dit object')
	})
	it('recomputes the archive nomination from the Archiving card, with a reason, through OpenRegister', () => {
		const [recompute] = simple.configPatch.widgets['case-archival-panel'].headerActions
		expect(nl[recompute.label]).toBe('Nominatie herberekenen')
		expect(recompute.action).toMatchObject({ type: 'open-modal', target: 'CnActionDialog', props: { objectId: '@objectId' } })
		const { config } = recompute.action.props
		expect(config.reason.label).toBe('Reason')
		expect(config.confirm.request).toEqual({
			method: 'POST',
			url: '/apps/openregister/api/archival/objects/@objectId/nomination/recompute',
			body: { reason: '{reason}' },
		})
	})
	it('links an existing meeting or makes one from the Afspraken card, through the calendar leaf', () => {
		const calendar = appended('case-calendar-card')
		expect(calendar.integrationId).toBe('calendar')
		expect(calendar.props.headerActions).toEqual(['link', 'create'])
	})
	it('makes a meeting from a task\'s Afspraken card, on the task itself, as board DqTaak draws only that button', () => {
		const task = manifest.pages.find((page) => page.id === 'TaskDetail')
		const appointments = task.config.widgets.find((widget) => widget.id === 'task-appointments')
		expect(appointments.type).toBe('events')
		expect(appointments.content.source).toEqual({ kind: 'flow-task' })
		expect(appointments.content.headerActions).toEqual(['create'])
	})
})
