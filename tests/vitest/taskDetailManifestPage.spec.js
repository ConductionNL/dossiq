// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The task page is a manifest detail page (task-page-on-the-manifest).
 *
 * TaskDetailView was a custom view of about 1,000 lines. Every function it
 * offered is now page config over library widgets;
 * these assertions are the no-loss list from the change's proposal, read off
 * the manifest. The library behaviour behind each key (the tasks record
 * source, its verbs, the checklist widget, the pill `values`) is specified and
 * tested in nextcloud-vue.
 *
 * @spec openspec/specs/task-management/spec.md#requirement-the-task-page-is-a-manifest-detail-page
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const page = manifest.pages.find((entry) => entry.id === 'TaskDetail')
const config = page.config

/**
 * A widget the page declares in `widgets` or `sideColumn`.
 *
 * @param {string} id The widget id.
 * @return {object|undefined} The definition.
 */
function widget(id) {
	return [...config.widgets, ...config.sideColumn].find((entry) => entry.id === id)
}

describe('the TaskDetail manifest page', () => {
	it('reads the engine task on the unchanged route and page id', () => {
		expect(page.route).toBe('/tasks/:id')
		expect(page.type).toBe('detail')
		expect(config.entitySource).toBe('tasks')
		expect(config).not.toHaveProperty('register')
		expect(config).not.toHaveProperty('schema')
	})

	it('draws the state in dossiq words and the priority only when high or urgent', () => {
		expect(config.typePill).toMatchObject({ field: 'state', labels: { active: 'In execution' }, colorMap: { active: 'purple', completed: 'success' } })
		expect(config.statusPill).toMatchObject({ field: 'priority', values: ['high', 'urgent'], colorMap: { high: 'warning', urgent: 'error' } })
	})

	it('has a breadcrumb back to Tasks and its case and deadline beside it (DqTaak "bij zaak 2026-0074, deadline vandaag")', () => {
		expect(config.breadcrumb).toMatchObject({ label: 'Tasks', route: 'Tasks' })
		expect(config.headerMeta).toBe('at case {subject.identifier}, deadline {dueAt|relative}')
		const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
		expect(nl[config.headerMeta]).toBe('bij zaak {subject.identifier}, deadline {dueAt|relative}')
	})

	it('completes through dossiq and every other verb through the engine', () => {
		expect(config.verbEndpoints).toEqual({ complete: '/apps/dossiq/api/case-tasks/{id}/complete' })
	})

	it('has an Overview tab with the description, checklist, appointments and notes, and a History tab', () => {
		const panels = widget('task-panels')
		expect(config.layout.map((item) => item.widgetId)).toEqual(['task-panels'])
		expect(panels.type).toBe('tabs')
		expect(panels.content.tabs).toEqual([
			{ label: 'Overview', widgetIds: ['task-checklist', 'task-appointments', 'task-notes'] },
			{ label: 'Task history', widgetId: 'task-history' },
		])
		// Board DqTaak draws no description card: the description is the first line of the side Task card.
		expect(widget('task-description')).toBeUndefined()
		expect(config.sideColumn.find((entry) => entry.id === 'side-task').content.fields[0]).toEqual({ key: 'description', as: 'text', path: 'description' })
		expect(widget('task-checklist')).toMatchObject({ type: 'checklist', content: { path: 'checklist' } })
		// The leaves are the library cards on the task's engine endpoints
		// (`source: flow-task`), with dossiq's empty lines kept.
		expect(widget('task-appointments')).toMatchObject({ type: 'events', content: { source: { kind: 'flow-task' }, emptyText: 'Nobody has booked an appointment on this task' } })
		// The add form sits behind "Add note" in the card header, as DqTaak draws it.
		expect(widget('task-notes')).toMatchObject({ type: 'integration', integrationId: 'notes', props: { source: { kind: 'flow-task', id: '@objectId' }, composer: 'button' } })
		expect(widget('task-history')).toMatchObject({ type: 'audit-trail', content: { source: { kind: 'flow-task' }, emptyText: 'Nothing has happened to this task yet' } })
	})

	it('has the task facts, the case card, the waiting-case card and the history beside the body', () => {
		expect(config.sideColumn.map((entry) => entry.id)).toEqual(['side-task', 'side-case', 'side-waiting', 'side-history'])
		expect(widget('side-task').content.fields.map((field) => field.path)).toEqual([
			'description', 'assignee', 'candidateGroups', 'dueAt', 'expiresAt', 'priority', 'requester', 'created',
		])
		// Board DqTaak "Team: Woo en informatie": the groups by display name, not by id.
		expect(widget('side-task').content.fields.find((field) => field.key === 'team')).toMatchObject({ format: 'list', itemFormat: 'group' })
		expect(widget('side-case')).toMatchObject({ type: 'facts', visibleWhen: { field: 'objectUuid', op: 'notEmpty' } })
		expect(widget('side-case').content.link.route).toEqual({ name: 'CaseDetail', params: { id: '@object.objectUuid' } })
		// Board DqTaak "Wat deze taak tegenhoudt": the step the case moves to
		// once this task is done (the case type's next status, server side) and
		// since when the flow has waited on it.
		expect(widget('side-waiting')).toMatchObject({
			type: 'facts',
			visibleWhen: { field: 'runUuid', op: 'notEmpty' },
			content: { endpointSource: { url: '/apps/dossiq/api/cases/@object.objectUuid/next-status' } },
		})
		expect(widget('side-waiting').content.fields[0].template).toBe('The case only moves to {name} once this task is done.')
		expect(widget('side-waiting').content.fields[1]).toMatchObject({ from: 'object', suffix: '.' })
		expect(widget('side-history')).toMatchObject({ type: 'audit-trail', content: { source: { kind: 'flow-task' } } })
	})

	it('names only widget types the library answers, with no dossiq task widget left', () => {
		const library = ['tabs', 'facts', 'checklist', 'events', 'integration', 'audit-trail', 'notice']
		for (const key of ['task-notes', 'task-events', 'task-audit', 'task-waiting-case']) {
			expect(registrySource).not.toMatch(new RegExp(`^\\t'${key}': \\{`, 'm'))
		}
		for (const entry of [...config.widgets, ...config.sideColumn]) {
			if (library.includes(entry.type)) {
				continue
			}
			expect(registrySource, entry.type).toMatch(new RegExp(`^\\t'${entry.type}': \\{`, 'm'))
		}
	})
})
