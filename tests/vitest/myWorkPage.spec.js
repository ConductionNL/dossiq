/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * My Work ("Aan mij toegewezen", DqAanMijToegewezen) is manifest config over
 * the library's index page: the reader's own open cases, cards first, urgency
 * by default from the work queue, newest as the other order, the tier pill on
 * every card, the coordinator's workload strip and the substituted work as
 * its two slots.
 *
 * @spec openspec/specs/my-work/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const page = manifest.pages.find((entry) => entry.id === 'MyWork')
const config = page.config

describe('My Work', () => {
	it('is an index page over the reader\'s own open cases', () => {
		expect(page.type).toBe('index')
		expect(config).toMatchObject({ register: 'dossiq', schema: 'case' })
		expect(config.filter).toEqual({ assignee: '@me', statusHiddenInLists: false, isDraft: false })
	})

	it('opens in cards and offers the table', () => {
		expect(config.viewMode).toBe('cards')
		expect(config.viewModes).toEqual(['cards', 'table'])
	})

	it('orders by urgency from the work queue first, newest second', () => {
		const [urgency, newest] = config.sortSelectOptions
		expect(urgency).toMatchObject({ value: 'urgency', default: true, order: 'deadline' })
		expect(urgency.source).toMatchObject({
			url: '/apps/dossiq/api/work-queue',
			rowsPath: 'items',
			rowPath: 'case',
			annotate: true,
			failedNote: 'Ordered by deadline: the urgency could not be computed.',
		})
		expect(urgency.source.carry).toContain('deadlineTier')
		expect(newest).toMatchObject({ value: 'newest', order: '-startDate' })
	})

	it('draws the deadline tier as each card\'s head pill, named as the board names it', () => {
		expect(config.cardLayout.headPill).toMatchObject({
			field: 'deadlineTier',
			labels: { overdue: 'Late', critical: 'Critical', warning: 'Soon', normal: 'Normal' },
		})
		expect(config.cardLayout.meta).toEqual([
			{ field: 'caseType', formatter: 'caseTypeName' },
			{ field: 'status', formatter: 'statusTypeName' },
		])
	})

	it('keeps the board header: Download, Actions and New case', () => {
		expect(config.headerButtons.map((button) => button.action)).toEqual(['export', 'actions-menu', 'add'])
		expect(config.createDefaults).toEqual({ assignee: '@me', confidentiality: 'vertrouwelijk' })
		expect(config.breadcrumb).toEqual({ label: 'My work', route: 'MyWorkHome' })
	})

	it('mounts the workload strip and the substituted work as its slots', () => {
		expect(page.slots).toEqual({ 'below-header': 'WorkloadSummaryBar', 'before-collection': 'SubstitutedWork' })
		expect(registry).toMatch(/\tWorkloadSummaryBar: \{\n\t\tkind: 'page',\n\t\tcomponent: WorkloadSummaryBar,/)
		expect(registry).toMatch(/\tSubstitutedWork: \{\n\t\tkind: 'page',\n\t\tcomponent: SubstitutedWork,/)
	})

	it('leaves the wrapper and its card behind', () => {
		expect(fs.existsSync(path.join(ROOT, 'src/views/MyWorkCards.vue'))).toBe(false)
		expect(fs.existsSync(path.join(ROOT, 'src/views/MyWorkCaseCard.vue'))).toBe(false)
		expect(registry).not.toContain('MyWorkView')
	})
})
