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
		expect(count('Case')).toBe(12)
		expect(count('Publication')).toBe(3)
		expect(count('Dossier')).toBe(1)
		expect(count('admin')).toBe(5)
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

	it('leaves every other widget of the page as it was', () => {
		const others = (config) =>
			config.widgets.filter((widget) => widget.id !== 'case-panels')
		expect(others(simple)).toEqual(others(original))
		expect(simple.layout).toEqual(original.layout)
		expect(simple.sidebar).toEqual(original.sidebar)
	})
})

describe('the header and the side column', () => {
	it('reads the status pill and the cards from fields the case carries', () => {
		expect(onTheCase(simple.statusPill.field)).toBe(true)
		// No type pill: the case holds its type as a uuid, and no name.
		expect(simple.typePill).toBeUndefined()
		expect(simple.sideColumn.map((card) => card.title)).toEqual([
			'Requester',
			'Handling',
		])
		for (const card of simple.sideColumn) {
			expect(card.type).toBe('data')
			expect(card.content.editable).toBe(false)
			for (const field of card.content.include) {
				expect(onTheCase(field), `${card.title}: ${field}`).toBe(true)
			}
		}
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
