/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cases list, the board and the dashboard in the simple structure.
 *
 * All three are overlays in `src/menu-layout.simple.json`. An overlay fails
 * quietly: a patch that names a lens which does not exist adds no count, a
 * filter on a field the case does not carry counts nothing and shows 0, and a
 * layout entry that names no widget leaves a hole. So every name here is
 * checked against the thing it names, and the full structure is held equal to
 * the manifest.
 *
 * @spec openspec/changes/simple-list-and-dashboard/specs/dashboard/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { spawnSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { cardDueSeverity } from '../../src/utils/cardDueSeverity.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const simpleFile = readJson('src', 'menu-layout.simple.json')
const fullFile = readJson('src', 'menu-layout.json')
const register = readJson('lib', 'Settings', 'dossiq_register.json')
const iconsSource = read('src', 'icons.js')
const registrySource = read('src', 'registry.js')
const cardSource = read('src', 'views', 'workflow-board', 'CaseCard.vue')

const WOO = '3c0f5a00-0000-4000-a000-00000000a001'

// The case schema is the main register plus what the fragments add to it.
const caseFields = new Set(Object.keys(register.components.schemas.case.properties))
for (const name of fs.readdirSync(
	path.join(ROOT, 'lib', 'Settings', 'register.d'),
)) {
	if (name.endsWith('.json')) {
		const fragment = readJson('lib', 'Settings', 'register.d', name)
		Object.keys(fragment.components?.schemas?.case?.properties ?? {}).forEach(
			(field) => caseFields.add(field),
		)
	}
}

function build(file) {
	return buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		file,
	)
}
const builtSimple = build(simpleFile)
const builtFull = build(fullFile)
const page = (built, id) => built.pages.find((item) => item.id === id)
function original(id) {
	return readJson('src', 'manifest.json').pages.find((item) => item.id === id)
}

/**
 * The case fields a filter narrows on, bracket and nested forms alike.
 *
 * @param {object} filter A widget or lens filter.
 * @return {Array<string>} The field names, lenses that start with `_` aside.
 */
function filterFields(filter) {
	return Object.keys(filter)
		.map((key) => key.replace(/\[.*$/, '').replace(/_isnull$/, ''))
		.filter((key) => !key.startsWith('_'))
}

describe('the full structure', () => {
	it('keeps the list, the board and the dashboard exactly as the manifest declares them', () => {
		for (const id of ['Cases', 'WorkflowBoard', 'Dashboard']) {
			expect(page(builtFull, id), id).toEqual(original(id))
		}
	})
})

describe('the cases list', () => {
	const simple = page(builtSimple, 'Cases').config
	const before = original('Cases').config

	it('leads with five views that each show a count', () => {
		expect(simple.quickFilterMaxVisible).toBe(5)
		const lead = simple.quickFilters.slice(0, 5)
		expect(lead.map((lens) => lens.label)).toEqual([
			'All',
			'Mine',
			'Due this week',
			'Waiting on the applicant',
			'Woo requests',
		])
		for (const lens of lead) {
			expect(lens.showCount, lens.label).toBe(true)
		}
		expect(lead[0].default).toBe(true)
	})

	it('keeps every one of the 19 lenses it had, with the filter it had', () => {
		expect(before.quickFilters).toHaveLength(19)
		expect(simple.quickFilters).toHaveLength(20)
		for (const lens of before.quickFilters) {
			const kept = simple.quickFilters.find(
				(item) => item.label === lens.label,
			)
			expect(kept, lens.label).toBeTruthy()
			expect(kept.filter, lens.label).toEqual(lens.filter)
		}
		// Behind the chip they keep their own order.
		const rest = (list) =>
			list
				.map((lens) => lens.label)
				.filter(
					(label) =>
						![
							'All',
							'Mine',
							'Due this week',
							'Waiting on the applicant',
							'Woo requests',
						].includes(label),
				)
		expect(rest(simple.quickFilters)).toEqual(rest(before.quickFilters))
	})

	it('narrows Woo requests on the seeded Woo case type', () => {
		const woo = simple.quickFilters.find((lens) => lens.label === 'Woo requests')
		expect(woo.filter).toEqual({
			caseType: WOO,
			statusHiddenInLists: false,
			isDraft: false,
		})
		expect(
			read('lib', 'Settings', 'register.d', '81-woo-verzoek.json'),
		).toContain(`"${WOO}"`)
	})

	it('shows six columns, each a field the case carries', () => {
		const keys = simple.columns.map((column) =>
			typeof column === 'string' ? column : column.key,
		)
		expect(keys).toEqual([
			'identifier',
			'title',
			'caseType',
			'status',
			'assignee',
			'deadline',
		])
		for (const key of keys) {
			expect(caseFields.has(key), key).toBe(true)
		}
		// Type and status keep the formatter and widget they had.
		for (const key of ['caseType', 'status']) {
			const was = before.columns.find((column) => column.key === key)
			const now = simple.columns.find((column) => column.key === key)
			expect(now.formatter).toBe(was.formatter)
			expect(now.widget).toBe(was.widget)
		}
	})

	it('draws the handler as an avatar and the deadline in colour, red from the day it ends', () => {
		const handler = simple.columns.find((column) => column.key === 'assignee')
		expect(handler).toMatchObject({
			widget: 'avatar',
			widgetProps: { user: true },
		})
		const deadline = simple.columns.find((column) => column.key === 'deadline')
		expect(deadline.widget).toBe('date')
		expect(deadline.widgetProps.variantWhen).toEqual([
			{ op: 'lte', value: 0, variant: 'error' },
			{ op: 'lte', value: 5, variant: 'warning' },
		])
	})

	it('touches nothing else on the list', () => {
		const rest = ({ quickFilters, quickFilterMaxVisible, columns, ...others }) =>
			others
		expect(rest(simple)).toEqual(rest(before))
	})
})

describe('the board', () => {
	const rule = page(builtSimple, 'WorkflowBoard').config.dueRule

	it('declares a due rule in the simple structure and none in the full one', () => {
		expect(rule).toEqual({
			field: 'deadline',
			variantWhen: [
				{ op: 'lte', value: 0, variant: 'error' },
				{ op: 'lte', value: 3, variant: 'warning' },
			],
		})
		expect(page(builtFull, 'WorkflowBoard').config.dueRule).toBeUndefined()
	})

	it('counts today as late with the rule, and only yesterday without it', () => {
		expect(cardDueSeverity(-1, rule)).toBe('overdue')
		expect(cardDueSeverity(0, rule)).toBe('overdue')
		expect(cardDueSeverity(1, rule)).toBe('warning')
		expect(cardDueSeverity(3, rule)).toBe('warning')
		expect(cardDueSeverity(4, rule)).toBe('ok')
		expect(cardDueSeverity(null, rule)).toBeNull()

		// The full structure: the card's rule as it always was.
		expect(cardDueSeverity(-1, null)).toBe('overdue')
		expect(cardDueSeverity(0, null)).toBe('warning')
		expect(cardDueSeverity(3, null)).toBe('warning')
		expect(cardDueSeverity(4, null)).toBe('ok')
	})

	it('is read by the card from the board page, which is the caller that matters', () => {
		// A rule nothing reads is a rule that does nothing.
		expect(cardSource).toContain('cnManifest: { default: null }')
		expect(cardSource).toContain(
			"pages.find((page) => page.id === 'WorkflowBoard')",
		)
		expect(cardSource).toContain(
			'return cardDueSeverity(this.daysRemaining, this.dueRule)',
		)
	})
})

describe('the dashboard', () => {
	const simple = page(builtSimple, 'Dashboard')
	const before = original('Dashboard')
	const widget = (id) => simple.config.widgets.find((item) => item.id === id)

	it('puts the design first: greeting, first today, four counts, the week, the steps, my tasks', () => {
		const top = simple.config.layout
			.filter((entry) => entry.gridY < 13)
			.sort((a, b) => a.gridY - b.gridY || a.gridX - b.gridX)
			.map((entry) => entry.widgetId)
		expect(top).toEqual([
			'simple-greeting',
			'simple-first-today',
			'simple-my-open',
			'simple-due-soon',
			'simple-waiting',
			'simple-closed-month',
			'simple-week',
			'simple-my-tasks',
			'simple-per-step',
		])
		expect(widget('simple-greeting').content).toEqual({
			greeting: true,
			showDate: true,
			plain: true,
		})
	})

	it('keeps everything the dashboard held, thirteen rows down and otherwise as it was', () => {
		for (const was of before.config.widgets) {
			expect(widget(was.id), was.id).toEqual(was)
		}
		for (const was of before.config.layout) {
			const now = simple.config.layout.find((entry) => entry.id === was.id)
			expect(now, was.widgetId).toEqual({ ...was, gridY: was.gridY + 13 })
		}
		expect(simple.config.layout).toHaveLength(before.config.layout.length + 9)
	})

	it('places every widget it adds, on a grid where no two cards overlap', () => {
		const ids = new Set(simple.config.widgets.map((item) => item.id))
		const cells = new Set()
		for (const entry of simple.config.layout) {
			expect(ids.has(entry.widgetId), entry.widgetId).toBe(true)
			expect(entry.gridX + entry.gridWidth).toBeLessThanOrEqual(12)
			for (let x = entry.gridX; x < entry.gridX + entry.gridWidth; x++) {
				for (let y = entry.gridY; y < entry.gridY + entry.gridHeight; y++) {
					const cell = `${x}:${y}`
					expect(
						cells.has(cell),
						`${entry.widgetId} overlaps at ${cell}`,
					).toBe(false)
					cells.add(cell)
				}
			}
		}
		const placed = new Set(simple.config.layout.map((entry) => entry.widgetId))
		for (const item of simple.config.widgets.filter((w) =>
			w.id.startsWith('simple-'),
		)) {
			expect(placed.has(item.id), item.id).toBe(true)
		}
	})

	it('counts and lists only over fields the case carries, and only MY cases', () => {
		const sources = [
			'simple-my-open',
			'simple-due-soon',
			'simple-waiting',
			'simple-closed-month',
			'simple-week',
			'simple-per-step',
		].map((id) => [id, widget(id).content.source])
		sources.push([
			'simple-first-today',
			widget('simple-first-today').content.visibleWhen.source,
		])
		for (const [id, source] of sources) {
			expect(source.register, id).toBe('dossiq')
			expect(source.schema, id).toBe('case')
			expect(source.filter.assignee, id).toBe('@me')
			for (const field of filterFields(source.filter)) {
				expect(caseFields.has(field), `${id}: ${field}`).toBe(true)
			}
		}
		expect(caseFields.has(widget('simple-week').content.dateField)).toBe(true)
		expect(
			caseFields.has(widget('simple-per-step').content.source.groupBy),
		).toBe(true)
	})

	it('names the steps by status roles a status can have', () => {
		const roles = register.components.schemas.statusType.properties.role.enum
		const bar = widget('simple-per-step').content
		expect(bar.source.groupBy).toBe('statusRole')
		for (const key of [...bar.order, ...Object.keys(bar.labels)]) {
			expect(roles, key).toContain(key)
		}
	})

	it('marks today as late in the week strip, like the list and the board', () => {
		expect(widget('simple-week').content.lateWhen).toEqual({
			op: 'lte',
			value: 0,
		})
		expect(widget('simple-week').content.itemRoute).toBe('CaseDetail')
	})

	it('shows First today only when a deadline of mine ends today, and links to pages that exist', () => {
		const card = widget('simple-first-today').content
		expect(card.layout).toBe('attention')
		expect(card.visibleWhen.op).toBe('gt')
		expect(card.visibleWhen.value).toBe(0)
		expect(card.visibleWhen.source.filter.deadline).toEqual({
			gte: '@today',
			lt: '@today+1d',
		})
		expect(card.actions.length).toBeLessThanOrEqual(2)
		const pageIds = new Set(builtSimple.pages.map((item) => item.id))
		for (const action of card.actions) {
			const name =
				typeof action.route === 'string' ? action.route : action.route.name
			expect(pageIds.has(name), name).toBe(true)
		}
	})

	it('gives my tasks the slot its custom widget resolves through', () => {
		expect(widget('simple-my-tasks').type).toBe('custom')
		expect(simple.slots['widget-simple-my-tasks']).toBe('MyWorkWidget')
		expect(registrySource).toContain('MyWorkWidget')
		// The same component, the same content, as on My work.
		const onMyWork = original('MyWorkHome').config.widgets.find(
			(item) => item.id === 'my-work',
		)
		expect(widget('simple-my-tasks').content).toEqual(onMyWork.content)
	})

	it('uses icons the app registers', () => {
		for (const item of simple.config.widgets.filter((w) =>
			w.id.startsWith('simple-'),
		)) {
			if (item.content?.icon) {
				expect(iconsSource, item.id).toContain(`\n\t${item.content.icon},\n`)
			}
		}
	})
})

describe('the built simple manifest', () => {
	it('still passes the schema the installed library ships', () => {
		const file = path.join(
			fs.mkdtempSync(path.join(os.tmpdir(), 'dossiq-simple-')),
			'manifest.json',
		)
		fs.writeFileSync(file, JSON.stringify(builtSimple))
		const run = spawnSync(
			'node',
			[path.join(ROOT, 'tests', 'validate-manifest.js')],
			{
				cwd: ROOT,
				env: { ...process.env, APP_MANIFEST: file },
				encoding: 'utf8',
			},
		)
		expect(run.stdout + run.stderr).toContain('PASS (0 errors)')
		expect(run.status).toBe(0)
	}, 120_000)
})
