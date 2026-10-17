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
 * @spec openspec/specs/case-management/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import {
	groupMenuEntries,
	resolveNextStep,
	stageEntry,
	stageOf,
} from '@conduction/nextcloud-vue/src/utils/detailActionModel.js'
import { schemaRefSlug } from '@conduction/nextcloud-vue/src/utils/schemaRefSlug.js'
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

	it('patches only actions that exist, and leaves none of the 34 unplaced', () => {
		const patched = Object.keys(overlay.configPatch.headerActions)
		for (const id of patched) {
			expect(ids, id).toContain(id)
		}
		// Every action is still there; the simple page orders them as DqZaakMeer lists them.
		// The simple page adds two acts: Add location (DqZaakLocatie) and
		// Share with another organisation (DqZaakSamenwerken).
		expect([...simple.headerActions.map((action) => action.id)].sort()).toEqual([...ids, 'add-location', 'share-with-organisation'].sort())
		expect(simple.headerActions.map((action) => action.id).slice(0, overlay.configOrder.headerActions.length)).toEqual(overlay.configOrder.headerActions)

		const placed = simple.headerActions.map((action) => {
			if (simple.quickActions.includes(action.id)) {
				return 'quick'
			}
			return action.adminOnly ? 'admin' : (action.group ?? 'top')
		})
		const count = (where) => placed.filter((place) => place === where).length
		expect(placed).toHaveLength(34)
		expect(count('quick')).toBe(3)
		expect(count('top')).toBe(1)
		expect(count('Case')).toBe(19)
		expect(count('Publication')).toBe(4)
		expect(count('Dossier')).toBe(5)
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
	const find = (config, id) =>
		config.widgets.find((widget) => widget.id === id)

	it('shows the five the board draws and keeps the other panels under Acties, losing none', () => {
		const strip = tabsOf(simple)
		expect(strip.maxVisibleTabs).toBe(5)
		expect(
			strip.tabs.filter((tab) => !tab.overflow).map((tab) => tab.label),
		).toEqual(['Overview', 'Documents', 'Contact', 'Tasks', 'Case history'])
		// The order DqZaakMeer lists them in under Acties.
		expect(
			strip.tabs.filter((tab) => tab.overflow).map((tab) => tab.label),
		).toEqual(['Notes', 'People', 'Email', 'Decisions', 'Related', 'Custody', 'Archiving', 'Knowledge', 'Proposals', 'Publication', 'Review'])
		const widgetIds = (content) => content.tabs
			.flatMap((tab) => (Array.isArray(tab.widgetIds) ? tab.widgetIds : [tab.widgetId]))
		// Every panel of the full strip is still on it, except the Data and
		// Communication panels, whose sections became the Overview and Contact
		// stacks' own cards.
		const shown = widgetIds(strip)
		const unfolded = ['case-data-panel', 'case-communication-panel', 'case-work-panel']
		for (const id of widgetIds(tabsOf(original)).filter((id) => !unfolded.includes(id))) {
			expect(shown, id).toContain(id)
		}
		const communication = find(original, 'case-communication-panel').content.sections.map((section) => section.widget.id)
		const contactCards = {
			'case-communication': 'case-contact-moments-card',
			'case-acknowledgement': 'case-acknowledgement-card',
			'case-conversations': 'case-conversations-card',
			'case-pipelinq-contact-moments': 'case-customer-record-card',
		}
		expect(Object.keys(contactCards).sort()).toEqual([...communication].sort())
		const contactTab = strip.tabs.find((tab) => tab.label === 'Contact')
		expect(contactTab.widgetIds).toEqual(Object.values(contactCards))
		const card = (id) => simple.widgets.find((widget) => widget.id === id)
		const dataSections = find(original, 'case-data-panel').content.sections.map((section) => section.widget.id)
		const movedTo = {
			'case-core': 'case-details',
			'case-priority': 'case-priority-card',
			'case-location-map': 'case-locations-card',
		}
		for (const id of dataSections) {
			if (movedTo[id]) {
				expect(shown, `${id} moved to ${movedTo[id]}`).toContain(movedTo[id])
				continue
			}
			const publication = card('case-publication').content.sections.map((section) => section.widget.id)
			expect(publication, `${id} is lost from the Overview`).toContain(id)
		}
		expect(shown).toContain('case-publication')
		// The fields case-core showed are on the Overview, as Case details or in their own card.
		const core = find(original, 'case-data-panel').content.sections[0].widget.content.include
		const onOverview = [...card('case-details').content.include, 'title', 'description', 'priority']
		for (const field of core) {
			expect(onOverview, field).toContain(field)
		}
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
				variant: 'bars',
				stagesEndpoint: undefined,
				stagesSource: {
					register: 'dossiq',
					schema: 'statusType',
					filter: { caseType: '@object.caseType' },
					orderBy: 'order',
					labelField: 'name',
					descriptionField: 'description',
					finalField: 'isFinal',
					limit: 50,
				},
			},
		})
		// The blueprint endpoint the full page reads lists only the statuses a
		// case type embeds (one, "Afgehandeld", on the seeded Woo type, seen
		// live 6 October 2026); the statusType collection, which the board
		// reads, holds all of them. The simple page reads that collection.
		expect('stagesEndpoint' in find(simple, 'case-stages').content).toBe(false)

		// The deadline tile only loses its own label in the side column, where
		// the card title already heads it (casePageLabelsHistoryAndTourFirst).
		// The terms, flows and hours are cards of the Overview stack, titled as
		// DqZaak titles them; the cards the simple page adds are its own.
		const patched = ['case-panels', 'case-stages', 'case-tile-deadline', 'case-terms', 'case-flow-runs', 'case-kpis-hours']
		const added = ['case-request', 'case-details', 'case-priority-card', 'case-locations-card', 'case-plan-card', 'case-publication', 'case-contact-moments-card', 'case-acknowledgement-card', 'case-conversations-card', 'case-customer-record-card', 'case-tasks-card', 'case-planned-actions-card', 'case-incidents-card', 'case-calendar-card', 'case-deck-card']
		const others = (config) =>
			config.widgets.filter((widget) => !patched.includes(widget.id) && !added.includes(widget.id))
		expect(others(simple)).toEqual(others(original))
		// The Tasks tab holds the work panel's sections as cards, in DqZaakTaken's order.
		const workSections = find(original, 'case-work-panel').content.sections.map((section) => section.widget.id)
		const tasksTab = tabsOf(simple).tabs.find((tab) => tab.label === 'Tasks')
		expect(tasksTab.widgetIds).toEqual(['case-tasks', 'case-planned-actions', 'case-incidents', 'case-calendar', 'case-deck'].map((id) => `${id}-card`))
		expect(tasksTab.widgetIds.map((id) => id.replace(/-card$/, '')).sort()).toEqual([...workSections].sort())
		expect(simple.sidebar).toEqual(original.sidebar)
	})

	it('reads the stages from a schema the register has, as the library sends it', () => {
		// The library runs every schema name through `schemaRefSlug` before it
		// builds the request. Up to 2.63.0 that kebab-cased a slug as well:
		// `statusType` went out as `/objects/dossiq/status-type` and answered
		// 404 on :8080 (6 October 2026), so the widget said "Could not load the
		// stages". nextcloud-vue 2.64.0 (#1337) passes a camelCase slug through
		// unchanged. This asks the library's own rule, so a name it would
		// rewrite cannot pass, whichever version is installed.
		const source = simple.widgets.find((widget) => widget.id === 'case-stages')
			.content.stagesSource
		const sent = schemaRefSlug(source.schema)
		expect(sent).toBe(source.schema)
		const slugs = Object.entries(register.components.schemas).map(
			([key, schema]) => String(schema.slug || key),
		)
		const named = slugs.find((slug) => slug.toLowerCase() === sent.toLowerCase())
		expect(named).toBe('statusType')
		expect(Object.keys(register.components.registers)).toContain(source.register)

		// Every field the source reads is a field the schema carries.
		const fields = Object.keys(register.components.schemas[named].properties)
		for (const field of [
			...Object.keys(source.filter),
			source.orderBy,
			source.labelField,
			source.descriptionField,
			source.finalField,
		]) {
			expect(fields).toContain(field)
		}
	})

	it('draws the progress as bars and the tabs as the board strip, with a way back to the list', () => {
		// DqZaak draws one bar per step with the label under it, the tab strip
		// as folder tabs (the board look's own strip, no variant), and
		// "All cases / <number>" above the card
		// instead of a CASE eyebrow. All three are nextcloud-vue 2.64.0 opt-ins,
		// so the full profile keeps drawing what it drew.
		const find = (config, id) =>
			config.widgets.find((widget) => widget.id === id)
		expect(find(simple, 'case-stages').content.variant).toBe('bars')
		expect(find(original, 'case-stages').content.variant).toBeUndefined()
		expect(find(simple, 'case-panels').content.variant).toBeUndefined()
		expect(find(original, 'case-panels').content.variant).toBeUndefined()
		expect(simple.showTypeEyebrow).toBe(false)
		// The trail ends in the case number, as DqZaak draws "Alle zaken / 2026-0082".
		expect(simple.breadcrumb).toEqual({ label: 'All cases', route: 'Cases', currentField: 'identifier', separator: '/' })
		expect(original.showTypeEyebrow).toBeUndefined()
		expect(original.breadcrumb).toBeUndefined()
		expect(casePage(simpleFile).route).toBeDefined()
		expect(
			build(simpleFile).pages.some(
				(page) => page.id === simple.breadcrumb.route,
			),
		).toBe(true)
	})

	it('takes the three tiles out of the grid and keeps every other card where it was', () => {
		// Beside the side column the grid is narrow, and number, type and
		// deadline drew as three full-width cards under each other.
		// The stages (`3`) sit in the header card (`config.headerWidget`) and the
		// banner stack in the side column, so neither keeps a grid cell.
		// The plan, terms, flows and hours are cards of the Overview tab now, as
		// DqZaak draws them, so they keep no grid cell either.
		const gone = ['kpi-1', 'kpi-2', 'kpi-5', '3', 'case-banners', '12', '11', '7', '6']
		expect(simple.layout.map((entry) => entry.id)).toEqual(
			original.layout
				.map((entry) => entry.id)
				.filter((id) => !gone.includes(id)),
		)
		// The body grid sits beside the side column at the library's 12 columns
		// (nextcloud-vue 2.81.0 keeps it a grid there; before it collapsed to one
		// stacked column under 1000px). The tabs card (`5`) leads, as DqZaak draws
		// it, with the plan, terms, flows and hours full width under it; the
		// cards the board does not draw pair up two to a row. Sizes are the
		// manifest's, except three that would scroll inside.
		const PAIRED = {
			'money-payments': 0,
			'money-contract': 6,
			'family-plan-panel': 0,
			'work-projects': 6,
			'documents-merge-to-pdf': 0,
		}
		for (const entry of simple.layout) {
			const was = original.layout.find((item) => item.id === entry.id)
			const half = entry.id in PAIRED
			expect(entry.gridX, entry.id).toBe(half ? PAIRED[entry.id] : 0)
			expect(entry.gridWidth, entry.id).toBe(half ? 6 : 12)
			// Taller where the cell would scroll inside: the tabs card (Overview
			// measured 784px over its 720; since nextcloud-vue 2.82.0 its sixteen
			// tabs wrap to three rows, 72px more), the flow runs and hours booked.
			const taller = { 5: 20 }
			expect(entry.gridHeight, entry.id).toBe(
				taller[entry.id] ?? was.gridHeight,
			)
		}
		const order = [...simple.layout]
			.sort((a, b) => a.gridY - b.gridY)
			.map((entry) => entry.id)
		expect(order.slice(0, 2)).toEqual(['presence-strip', '5'])
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
		// None of the three is lost: the number ends the breadcrumb,
		// the deadline is the first card of the side column, and neither
		// widget is still placed in the grid, so nothing renders twice.
		expect(simple.breadcrumb.currentField).toBe('identifier')
		expect(simple.typePill).toBeUndefined()
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
		expect(onTheCase(simple.breadcrumb.currentField)).toBe(true)
		const cards = simple.sideColumn.filter((card) => typeof card === 'object')
		expect(cards.map((card) => card.title)).toEqual(['Requester', 'Handling', 'Work instruction'])
		const shown = []
		for (const card of cards) {
			// The requester is a facts card (a lead, as DqZaak draws it), the
			// handling a read-only data card, the work instruction its own pane.
			const fields = card.type === 'facts'
				? card.content.fields.map((entry) => entry.path)
				: card.type === 'data' ? card.content.include : []
			if (card.type === 'data') {
				expect(card.content.editable).toBe(false)
			}
			for (const field of fields) {
				expect(onTheCase(field), `${card.title}: ${field}`).toBe(true)
				shown.push(field)
			}
		}
		// Nothing the requester card showed before is gone.
		expect(shown).toEqual(expect.arrayContaining(['initiatorDisplayName', 'initiatorType', 'intakeChannel']))
		// The deadline has its own card, so no data card repeats it.
		expect(shown).not.toContain('deadline')
	})

	it('draws the header as a card holding the stages, and the banners in the side column', () => {
		// DqZaak puts the progress bars inside the header card, and draws no
		// favourites, follow, dwell or attention row above the tabs. Both are
		// nextcloud-vue 2.65.0 opt-ins (`headerCard`, `headerWidget`); the
		// banner stack moves to the bottom of the side column, so nothing it
		// offers is lost. The full profile declares none of this.
		expect(simple.headerCard).toBe(true)
		expect(simple.headerWidget).toBe('case-stages')
		expect(simple.widgets.some((widget) => widget.id === 'case-stages')).toBe(
			true,
		)
		expect(simple.layout.some((entry) => entry.widgetId === 'case-stages')).toBe(
			false,
		)
		expect(simple.sideColumn.at(-1)).toBe('case-banner-stack')
		expect(
			simple.widgets.some((widget) => widget.id === 'case-banner-stack'),
		).toBe(true)
		expect(
			simple.layout.some((entry) => entry.widgetId === 'case-banner-stack'),
		).toBe(false)
		expect(original.headerCard).toBeUndefined()
		expect(original.headerWidget).toBeUndefined()
		expect(
			original.layout.some((entry) => entry.widgetId === 'case-banner-stack'),
		).toBe(true)
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
		// A lag of the installed library is not a defect here (validate-manifest.js
		// tells them apart); an error of this app's own still fails.
		expect(run.stdout + run.stderr).toMatch(
			/PASS \(0 errors(\)| of this app's own)/,
		)
		expect(run.status).toBe(0)
	}, 120_000)
})
