// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page in the simple structure.
 *
 * The page is an overlay in `src/menu-layout.simple.json` on the manifest's
 * `CaseDetail`. An overlay fails quietly: a patch that names an action which
 * does not exist changes nothing, a stage keyed on a role no status can have
 * never shows, and a checklist item reading a field the case does not carry
 * is simply never done. So every name in the overlay is checked here against
 * the thing it names, and the stage logic is asked of the library's own
 * resolver.
 *
 * jsdom, because the library's resolver imports `@nextcloud/auth`.
 *
 * @spec openspec/changes/simple-case-page/specs/case-management/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import {
	groupMenuEntries,
	resolveNextStep,
	stageEntry,
	stageOf,
} from '@conduction/nextcloud-vue/src/utils/detailActionModel.js'
import { spawnSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { describe, expect, it } from 'vitest'
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
const wooSeed = readJson('lib', 'Settings', 'register.d', '81-woo-verzoek.json')
const registrySource = read('src', 'registry.js')

const caseSchema = register.components.schemas.case
// The case schema is the main register plus what the `register.d` fragments
// add to it, the way the import merges them.
const caseFields = new Set(Object.keys(caseSchema.properties))
for (const name of fs.readdirSync(
	path.join(ROOT, 'lib', 'Settings', 'register.d'),
)) {
	if (!name.endsWith('.json')) {
		continue
	}
	const fragment = readJson('lib', 'Settings', 'register.d', name)
	for (const field of Object.keys(
		fragment.components?.schemas?.case?.properties ?? {},
	)) {
		caseFields.add(field)
	}
}
const roles = register.components.schemas.statusType.properties.role.enum

function build(file) {
	return buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		file,
	)
}
const casePage = (file) => build(file).pages.find((page) => page.id === 'CaseDetail')

const overlay = simpleFile.pages.find((page) => page.id === 'CaseDetail')
const simple = casePage(simpleFile).config
const original = readJson('src', 'manifest.json').pages.find(
	(page) => page.id === 'CaseDetail',
).config

/**
 * Whether the case schema carries a field, `@self` paths aside.
 *
 * @param {string} field The field a checklist item or a card reads.
 * @return {boolean} True when the case can hold it.
 */
function onTheCase(field) {
	return field.startsWith('@self.') || caseFields.has(field)
}

describe('the full structure', () => {
	it('keeps the case page exactly as the manifest declares it', () => {
		expect(casePage(fullFile).config).toEqual(original)
	})
})

describe('the stage', () => {
	it('is the role of the status, materialised on the case and never defaulted', () => {
		expect(simple.stageField).toBe('statusRole')
		const calculation =
			caseSchema.configuration['x-openregister-calculations'].statusRole
		expect(calculation.materialise).toBe(true)
		// No coalesce: a status without a role must leave the stage EMPTY, so
		// the page degrades instead of pretending the case is in handling.
		expect(calculation.expression).toEqual({ prop: '@ref.statusType.role' })
		expect(caseSchema.properties.statusRole).toMatchObject({
			type: 'string',
			readOnly: true,
		})
		expect(caseSchema.properties.statusRole.default).toBeUndefined()
	})

	it('only names roles a status can have', () => {
		for (const stage of [
			...Object.keys(simple.primaryActionByStage),
			...Object.keys(simple.nextStep.stages),
		]) {
			expect(roles, stage).toContain(stage)
		}
	})

	it('gives every status of the Woo request case type a role', () => {
		const statuses = wooSeed.components.objects.filter(
			(object) => object['@self']?.schema === 'statusType',
		)
		expect(statuses).toHaveLength(8)
		for (const status of statuses) {
			expect(roles, status.name).toContain(status.role)
		}
		expect(statuses.map((status) => status.role)).toEqual(
			expect.arrayContaining(['intake', 'in-progress', 'review', 'closed']),
		)
	})

	it('points every stage button at an action or a dialog that exists', () => {
		const ids = new Set(original.headerActions.map((action) => action.id))
		for (const [stage, entry] of Object.entries(simple.primaryActionByStage)) {
			if (typeof entry === 'string') {
				expect(ids.has(entry), `${stage} -> ${entry}`).toBe(true)
				continue
			}
			expect(entry.type, stage).toBe('open-modal')
			expect(registrySource, `${stage} -> ${entry.target}`).toContain(
				`\n\t${entry.target}: {`,
			)
			expect(
				ids.has(entry.id),
				`${entry.id} collides with a header action`,
			).toBe(false)
		}
	})

	it('gives every stage that shows a card a button that cannot hide', () => {
		// Live check, 5 October 2026: intake pointed at Claim, Claim hides once
		// the case has a handler, and the card showed two done items and no
		// button. A stage button may only carry a condition a working case
		// always passes.
		for (const stage of Object.keys(simple.nextStep.stages)) {
			const entry = stageEntry(simple.primaryActionByStage, stage)
			expect(entry, `${stage} shows a card and no button`).toBeTruthy()
			if (typeof entry === 'object') {
				expect(entry.visibleWhen, stage).toBeUndefined()
				expect(entry.target, stage).toBe('CaseLifecycleMenuDialog')
				continue
			}
			const action = original.headerActions.find((item) => item.id === entry)
			expect(action.visibleWhen, `${stage} -> ${entry}`).toEqual({
				field: '@self.archived',
				op: 'empty',
			})
		}
		// Claim is not a stage button any more, and is still in the menu.
		expect(JSON.stringify(simple.primaryActionByStage)).not.toContain(
			'case-claim',
		)
		expect(
			simple.headerActions.find((item) => item.id === 'case-claim').group,
		).toBe('Case')
	})

	it('degrades on a case whose status has no role: no card, no stage button', () => {
		for (const record of [
			{ id: 'a', assignee: 'jan' },
			{ id: 'b', statusRole: null },
			{ id: 'c', statusRole: '' },
		]) {
			expect(stageOf(record, simple.stageField)).toBe('')
			expect(
				stageEntry(
					simple.primaryActionByStage,
					stageOf(record, simple.stageField),
				),
			).toBeUndefined()
			expect(
				resolveNextStep(simple.nextStep, record, simple.stageField),
			).toBeNull()
		}
		// And Lifecycle is still there for that case, first in the menu:
		// ungrouped, not admin only, not pinned as a quick action.
		const lifecycle = simple.headerActions.find(
			(action) => action.id === 'case-lifecycle-menu',
		)
		expect(lifecycle.group).toBeUndefined()
		expect(lifecycle.adminOnly).toBeUndefined()
		expect(simple.quickActions).not.toContain('case-lifecycle-menu')
	})

	it('shows the card for a Woo request in handling, with the open item first', () => {
		const card = resolveNextStep(
			simple.nextStep,
			{
				id: 'w',
				statusRole: 'in-progress',
				assignee: 'pieter',
				isIncomplete: true,
			},
			simple.stageField,
		)
		expect(card.title).toBe('What now? Handle the case')
		expect(card.items.map((item) => [item.label, item.done])).toEqual([
			['Choose a handler', true],
			['Complete the case details', false],
			['The applicant has answered', true],
		])
	})

	it('builds every checklist from fields the case really carries', () => {
		for (const [stage, entry] of Object.entries(simple.nextStep.stages)) {
			expect(entry.checklist.length, stage).toBeGreaterThan(0)
			for (const item of entry.checklist) {
				const field = item.doneField ?? item.doneWhen?.field
				expect(field, `${stage}: ${item.label}`).toBeTruthy()
				expect(onTheCase(field), `${stage}: ${field}`).toBe(true)
			}
		}
	})
})

describe('the actions', () => {
	const ids = original.headerActions.map((action) => action.id)

	it('patches only actions that exist, and leaves none of the 25 unplaced', () => {
		const patched = Object.keys(overlay.configPatch.headerActions)
		for (const id of patched) {
			expect(ids, id).toContain(id)
		}
		expect(simple.headerActions.map((action) => action.id)).toEqual(ids)

		const placed = simple.headerActions.map((action) => {
			if (simple.quickActions.includes(action.id)) {
				return 'quick'
			}
			return action.adminOnly ? 'admin' : (action.group ?? 'top')
		})
		const count = (where) => placed.filter((place) => place === where).length
		expect(placed).toHaveLength(25)
		expect(count('quick')).toBe(3)
		expect(count('top')).toBe(1)
		expect(count('Case')).toBe(15)
		expect(count('Publication')).toBe(3)
		expect(count('Dossier')).toBe(1)
		expect(count('admin')).toBe(2)
	})

	it('regroups actions and never changes who may use one', () => {
		// Every gate the manifest declares survives the overlay untouched.
		for (const action of original.headerActions) {
			const shown = simple.headerActions.find((item) => item.id === action.id)
			expect(shown.visibleWhen, action.id).toEqual(action.visibleWhen)
		}
		// Changing a case's type follows its own permission endpoint, in both
		// structures. `adminOnly` would have narrowed it to administrators.
		const rebind = simple.headerActions.find(
			(action) => action.id === 'case-rebind',
		)
		expect(rebind.visibleWhen).toEqual({
			endpoint: '/apps/dossiq/api/rebind/permission',
			field: 'mayRebind',
			op: 'eq',
			value: true,
		})
		expect(rebind.adminOnly).toBeUndefined()
		expect(rebind.group).toBe('Case')
		// The only admin-only actions are the two that were already gated on
		// the admin probe, so `adminOnly` takes nothing from anyone.
		const narrowed = simple.headerActions
			.filter((action) => action.adminOnly)
			.map((action) => action.id)
		expect(narrowed).toEqual(['case-inspect-raw', 'case-inspect-runs'])
		for (const id of narrowed) {
			const action = original.headerActions.find((item) => item.id === id)
			expect(action.visibleWhen.field, id).toBe('isAdmin')
		}
	})

	it('keeps three quick actions: message, document, contact', () => {
		expect(simple.quickActions).toEqual([
			'send-digital-post',
			'generate-document',
			'log-contact',
		])
	})

	it('puts the admin group last and hides it from a handler, by the library grouping', () => {
		const entries = simple.headerActions
			.filter((action) => !simple.quickActions.includes(action.id))
			.map((action) => ({ id: action.id, label: action.label }))
		const byId = Object.fromEntries(
			simple.headerActions.map((action) => [action.id, action]),
		)
		const adminIds = simple.headerActions
			.filter((action) => action.adminOnly)
			.map((action) => action.id)

		const forHandler = JSON.stringify(
			groupMenuEntries(entries, byId, { isAdmin: false }),
		)
		for (const id of adminIds) {
			expect(forHandler, id).not.toContain(`"${id}"`)
		}
		const forAdmin = JSON.stringify(
			groupMenuEntries(entries, byId, { isAdmin: true }),
		)
		const lastAdmin = Math.max(
			...adminIds.map((id) => forAdmin.indexOf(`"${id}"`)),
		)
		const lastOther = Math.max(
			...entries
				.filter((entry) => !adminIds.includes(entry.id))
				.map((entry) => forAdmin.indexOf(`"${entry.id}"`)),
		)
		expect(
			Math.min(...adminIds.map((id) => forAdmin.indexOf(`"${id}"`))),
		).toBeGreaterThan(lastOther)
		expect(lastAdmin).toBeGreaterThan(-1)
	})

	it('takes Refresh and the help links out of the record menu', () => {
		expect(simple.actionsMenu).toEqual({
			showRefresh: false,
			showHelpLinks: false,
			label: 'More',
		})
	})
})

describe('the tabs', () => {
	const tabsOf = (config) =>
		config.widgets.find((widget) => widget.id === 'case-panels').content

	it('shows five and keeps the other eight under More, losing none', () => {
		const strip = tabsOf(simple)
		expect(strip.maxVisibleTabs).toBe(5)
		expect(
			strip.tabs.filter((tab) => !tab.overflow).map((tab) => tab.label),
		).toEqual(['Overview', 'Documents', 'Contact', 'Tasks', 'History'])
		expect(strip.tabs.filter((tab) => tab.overflow)).toHaveLength(8)
		const widgetIds = (content) => content.tabs.map((tab) => tab.widgetId).sort()
		expect(widgetIds(strip)).toEqual(widgetIds(tabsOf(original)))
	})

	it('turns the stages horizontal and leaves every other widget of the page as it was', () => {
		const find = (config, id) =>
			config.widgets.find((widget) => widget.id === id)
		// The design draws the case's progress as a bar across the header
		// card. The widget keeps everything but its orientation.
		expect(find(simple, 'case-stages')).toEqual({
			...find(original, 'case-stages'),
			content: {
				...find(original, 'case-stages').content,
				orientation: 'horizontal',
			},
		})
		const others = (config) =>
			config.widgets.filter(
				(widget) =>
					widget.id !== 'case-panels' && widget.id !== 'case-stages',
			)
		expect(others(simple)).toEqual(others(original))
		expect(simple.sidebar).toEqual(original.sidebar)
	})

	it('takes the three tiles out of the grid and keeps every other card where it was', () => {
		// Beside the side column the grid is narrow, and number, type and
		// deadline drew as three full-width cards under each other.
		const gone = ['kpi-1', 'kpi-2', 'kpi-5']
		expect(simple.layout.map((entry) => entry.id)).toEqual(
			original.layout
				.map((entry) => entry.id)
				.filter((id) => !gone.includes(id)),
		)
		for (const entry of simple.layout) {
			const was = original.layout.find((item) => item.id === entry.id)
			if (entry.id === '3') {
				// The stages take the tile row, full width, above the tabs: the
				// design draws the progress bar first (DqZaak).
				expect(entry).toEqual({
					...was,
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 2,
					showTitle: false,
				})
				continue
			}
			if (entry.id === '6') {
				// Hours booked moves to where the stages were, beside the tabs.
				expect(entry).toEqual({
					...was,
					gridX: 9,
					gridY: 4,
					gridWidth: 3,
					gridHeight: 2,
				})
				continue
			}
			expect(entry, entry.id).toEqual(was)
		}
		// No two cards share a cell.
		const cells = new Set()
		for (const entry of simple.layout) {
			for (let x = entry.gridX; x < entry.gridX + entry.gridWidth; x++) {
				for (let y = entry.gridY; y < entry.gridY + entry.gridHeight; y++) {
					expect(
						cells.has(`${x}:${y}`),
						`${entry.widgetId} at ${x}:${y}`,
					).toBe(false)
					cells.add(`${x}:${y}`)
				}
			}
		}
		// None of the three is lost: the number is the pill above the title,
		// the deadline is the first card of the side column, and neither
		// widget is still placed in the grid, so nothing renders twice.
		expect(simple.typePill).toEqual({ field: 'identifier' })
		expect(simple.sideColumn[0]).toBe('case-tile-deadline')
		const placed = simple.layout.map((entry) => entry.widgetId)
		expect(placed).not.toContain('case-tile-deadline')
		expect(
			simple.widgets.some((widget) => widget.id === 'case-tile-deadline'),
		).toBe(true)
	})
})

describe('the header and the side column', () => {
	it('reads the pills and the cards from fields the case carries', () => {
		expect(onTheCase(simple.statusPill.field)).toBe(true)
		expect(onTheCase(simple.typePill.field)).toBe(true)
		const cards = simple.sideColumn.filter((card) => typeof card === 'object')
		expect(cards.map((card) => card.title)).toEqual(['Requester', 'Handling'])
		const shown = []
		for (const card of cards) {
			expect(card.type).toBe('data')
			expect(card.content.editable).toBe(false)
			for (const field of card.content.include) {
				expect(onTheCase(field), `${card.title}: ${field}`).toBe(true)
				shown.push(field)
			}
		}
		// The deadline has its own card, so no data card repeats it.
		expect(shown).not.toContain('deadline')
	})
})

describe('the built simple manifest', () => {
	it('passes the schema the installed library ships', () => {
		const file = path.join(
			fs.mkdtempSync(path.join(os.tmpdir(), 'dossiq-simple-')),
			'manifest.json',
		)
		fs.writeFileSync(file, JSON.stringify(build(simpleFile)))
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
