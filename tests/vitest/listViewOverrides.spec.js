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

	it('locks the queue to open cases nobody picked up, which the reader cannot clear, and opens on the reader\'s team', () => {
		expect(view('team-queue').filters).toEqual({ assignedGroup: '@myTeam' })
		expect(overrides.lockedFilters).toEqual({ assignee: 'IS NULL', isFinalStatus: false, statusHiddenInLists: false, isDraft: false, isTemplate: false })
	})

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
		// The badge counts what the list shows: its team filter and its locked filters.
		expect(badge.count.filter).toEqual({ ...view('team-queue').filters, ...view('team-queue').overrides.lockedFilters })
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

	it('locks the reader to their own cases, as the page did', () => {
		expect(view('my-work').filters).toEqual({})
		expect(overrides.lockedFilters).toEqual({ assignee: '@me', statusHiddenInLists: false, isDraft: false })
	})

	// Board DqAanMijToegewezen: "Woo-verzoek · Stichting Kindzorg", the cover line
	// in the purple ink, and a footer with the status pill in its status type's
	// colour and "Termijn 9 okt"; no description and no chips.
	it('draws type and requester, the cover line, and the status pill beside the overdue deadline', () => {
		expect(overrides.cardLayout.meta).toEqual([{ field: 'caseType', formatter: 'caseTypeName' }, 'initiatorDisplayName'])
		expect(overrides.cardLayout.note).toEqual({ field: 'coveredFor', formatter: 'coveredFor', tone: 'purple' })
		expect(overrides.cardLayout.footerPill).toEqual({ field: 'status', resolve: { schema: 'statusType', colorField: 'colour' } })
		expect(overrides.cardLayout.footerDate).toEqual({ field: 'deadline', label: 'Deadline', overdueVariant: 'error' })
		expect(overrides.cardLayout.description).toBeUndefined()
		expect(overrides.cardLayout.chips).toBeUndefined()
	})

	it('draws the deadline tier as each card\'s head pill', () => {
		expect(overrides.cardLayout.headPill).toMatchObject({
			field: 'deadlineTier',
			labels: { overdue: 'Late', critical: 'Critical', warning: 'Soon', normal: 'Normal' },
			// Board DqAanMijToegewezen: "Te laat" and "Kritiek" red, "Bijna" amber, "Normaal" grey.
			colorMap: { overdue: 'error', critical: 'error', warning: 'warning', normal: 'default' },
		})
		expect(overrides.cardLayout).toMatchObject({ number: 'identifier', title: 'title' })
	})

	it('is a menu entry that keeps the MyWork id, so its pins still lead somewhere', () => {
		expect(manifest.menu.find((item) => item.id === 'MyWork')).toMatchObject({ route: 'Cases', query: { savedView: 'default:my-work' } })
		expect(manifest.personalisation.pinnedMenu).toEqual(['Queue', 'MyWork', 'Cases'])
		expect(full.relocations.MyWork).toBe('WorkGroup')
		expect(simple.removals).toContain('MyWork')
	})

	it('mounts the workload strip, and no substituted-work card: the work queue lists covered work', () => {
		expect(overrides.slots).toEqual({ 'below-header': ['WorkloadTeamHeading'], 'toolbar-start': ['WorkloadTeamChips'] })
		expect(registry).toMatch(/\tWorkloadTeamHeading: \{\n\t\tkind: 'page',\n\t\tcomponent: WorkloadTeamHeading,/)
		expect(registry).toMatch(/\tWorkloadTeamChips: \{\n\t\tkind: 'page',\n\t\tcomponent: WorkloadTeamChips,/)
		expect(registry).not.toMatch(/\tSubstitutedWork: \{/)
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

	it('locks the list to the request kinds', () => {
		expect(entry.filters).toEqual({})
		expect(overrides.lockedFilters).toEqual({ dataSubjectRequestType: ['inzage', 'correctie', 'verwijdering'] })
	})

	it('opens ordered by deadline, soonest first, then by receipt', () => {
		expect(entry.sort).toEqual([{ key: 'deadline', order: 'asc' }, { key: 'receivedAt', order: 'asc' }])
	})
})

describe('the data subject requests are still filed from their view', () => {
	const overrides = view('data-subject-requests').overrides

	it('offers New data subject request, whose form asks the request kind', () => {
		expect(overrides.headerButtons.find((button) => button.action === 'add')).toMatchObject({ label: 'New data subject request' })
		expect(overrides.includeFields).toContain('dataSubjectRequestType')
	})
})

describe('no case-type-specific pages or menu entries', () => {
	const routes = fs.readFileSync(path.join(ROOT, 'src/utils/manifestRoutes.js'), 'utf8')
	const subsidie = read('src/manifest.d/50-subsidie.json')

	it('lists the subsidies as a default view of Cases, and /subsidies redirects to it', () => {
		expect(view('subsidies').filters.caseType).toBe('b3c1a000-0000-4000-a000-0000000005ab')
		expect(subsidie.pages.map((page) => page.id)).toEqual(['SubsidieDetail', 'Terugvorderingen'])
		expect(subsidie.menu).toBeUndefined()
		expect(routes).toMatch(/path: '\/subsidies',\n\t\tredirect: \{ name: 'Cases', query: \{ savedView: 'default:subsidies' \} \}/)
	})

	it('names no removed entry in either profile', () => {
		for (const file of [full, simple]) {
			expect(file.removals).not.toContain('SubsidiesGroup')
			expect(Object.keys(file.removalsReplacedBy)).not.toContain('SubsidiesGroup')
		}
	})

	it('keeps the own-record entries out of the main menu', () => {
		expect(manifest.menu.find((item) => item.id === 'BezwaarCommitteesMenu').section).toBe('settings')
		expect(full.settingsSection).toContain('CaseObjectsMenu')
		expect(simple.settingsSection).toContain('CaseObjectsMenu')
	})
})
