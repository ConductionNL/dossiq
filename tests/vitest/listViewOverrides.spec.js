/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lists that became views of the Cases page carry what their pages had,
 * as the view's page config `overrides` (lists-are-views, restore.md): the
 * team queue's counts, chips, side cards, columns and menu badge; Assigned to
 * me's cards, urgency order and two slots; the data subject requests' tabs,
 * notice, chips and columns.
 *
 * @spec openspec/changes/lists-are-views/specs/case-lists/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const manifest = read('src/manifest.json')
const simple = read('src/menu-layout.simple.json')
const full = read('src/menu-layout.json')
const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const cases = manifest.pages.find((page) => page.id === 'Cases').config
const view = (slug) => cases.defaultSavedViews.find((entry) => entry.slug === slug)

describe('the team-queue view', () => {
	const overrides = view('team-queue').overrides

	it('has the three counts, the chip per case type and the two side cards', () => {
		expect(overrides.statRow.map((tile) => tile.content.valueField)).toEqual(['queueCount', 'queueOldestDays', 'queueDueSoon'])
		expect(overrides.quickFiltersFrom).toMatchObject({ field: 'caseType', schema: 'caseType', allLabel: 'All types' })
		expect(overrides.sidePanel.map((card) => card.id)).toEqual(['queue-team-today', 'queue-by-type'])
	})

	it('lists how long each case has waited, in front', () => {
		expect(overrides.columns.map((column) => column.key)).toEqual(['startDate', 'title', 'caseType', 'deadline'])
		expect(overrides.columns[0]).toMatchObject({ label: 'Waiting', widget: 'age' })
	})

	it('is a menu entry with a count badge that keeps the Queue id, so its pins still lead somewhere', () => {
		const entry = manifest.menu.find((item) => item.id === 'Queue')
		expect(entry).toMatchObject({ route: 'Cases', query: { savedView: 'default:team-queue' } })
		expect(manifest.personalisation.pinnedMenu).toContain('Queue')
		expect(full.relocations.Queue).toBe('WorkGroup')
		const badge = simple.menu.find((item) => item.id === 'Queue')
		expect(badge).toMatchObject({ label: 'Team queue', counterVariant: 'attention' })
		expect(badge.count.filter).toEqual(view('team-queue').filters)
	})
})

describe('the my-work view', () => {
	const overrides = view('my-work').overrides

	it('opens on the urgency order, in cards, with the table offered', () => {
		expect(overrides.sortOption).toBe('urgency')
		expect(cases.sortSelectOptions.map((option) => option.value)).toContain('urgency')
		expect(overrides.viewMode).toBe('cards')
		expect(overrides.viewModes).toEqual(['cards', 'table'])
	})

	it('draws the deadline tier as each card\'s head pill', () => {
		expect(overrides.cardLayout.headPill).toMatchObject({
			field: 'deadlineTier',
			labels: { overdue: 'Late', critical: 'Critical', warning: 'Soon', normal: 'Normal' },
		})
		expect(overrides.cardLayout).toMatchObject({ number: 'identifier', title: 'title', footerDate: { field: 'deadline' } })
	})

	it('mounts the workload strip and the substituted work, both registered', () => {
		expect(overrides.slots).toEqual({ 'below-header': ['WorkloadSummaryBar'], 'before-collection': ['SubstitutedWork'] })
		expect(registry).toMatch(/\tWorkloadSummaryBar: \{\n\t\tkind: 'page',\n\t\tcomponent: WorkloadSummaryBar,/)
		expect(registry).toMatch(/\tSubstitutedWork: \{\n\t\tkind: 'page',\n\t\tcomponent: SubstitutedWork,/)
	})
})

describe('the data-subject-requests view', () => {
	const entry = view('data-subject-requests')
	const overrides = entry.overrides
	const self = { route: 'Cases', query: { savedView: 'default:data-subject-requests' } }

	it('has the two tabs, the first and the breadcrumb landing on the view', () => {
		expect(overrides.pageTabs.map((tab) => tab.label)).toEqual(['Data subject requests', 'Data breaches'])
		expect(overrides.pageTabs[0]).toMatchObject(self)
		expect(overrides.pageTabs[1]).toMatchObject({ route: 'AvgIncidents', permission: 'privacy' })
		expect(overrides.breadcrumb).toMatchObject({ label: 'Privacy', ...self })
	})

	it('has the notice, the kind chips and the Kind and Term columns', () => {
		expect(overrides.notice.variant).toBe('info')
		expect(overrides.quickFilters.map((chip) => chip.filter.dataSubjectRequestType ?? null)).toEqual([null, 'inzage', 'correctie', 'verwijdering'])
		expect(overrides.columns.map((column) => (typeof column === 'string' ? column : column.key))).toEqual(
			['title', 'dataSubjectRequestType', 'startDate', 'deadline', 'status', 'assignee'],
		)
	})

	it('opens ordered by deadline, soonest first', () => {
		expect(entry.sort).toEqual([{ key: 'deadline', order: 'asc' }])
	})
})
