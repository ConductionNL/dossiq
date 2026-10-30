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
import { BUILT_IN_MODALS } from '@conduction/nextcloud-vue/src/utils/builtInModals.js'
import {
	groupMenuEntries,
	resolveNextStep,
	stageEntry,
	stageOf,
} from '@conduction/nextcloud-vue/src/utils/detailActionModel.js'
import { firstFilled } from '@conduction/nextcloud-vue/src/utils/fillTemplate.js'
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
// A quick action is an id, or `{ id, notTabs }` for one a tab leaves out.
const quickIds = simple.quickActions.map((entry) => (typeof entry === 'string' ? entry : entry.id))
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
			// The app's registry, or a dialog the library opens by name.
			if (!Object.hasOwn(BUILT_IN_MODALS, entry.target)) {
				expect(registrySource, `${stage} -> ${entry.target}`).toContain(
					`\n\t${entry.target}: {`,
				)
			}
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
				// The one condition a working case always passes: not archived yet.
				if (entry.visibleWhen !== undefined) {
					expect(entry.visibleWhen, stage).toEqual({ field: '@self.archived', op: 'empty' })
				}
				// The closed stage opens the archive dialog (board DqArchiveren);
				// every other stage opens the Lifecycle dialog.
				expect(entry.target, stage).toBe(stage === 'closed' ? 'CnActionDialog' : 'CaseLifecycleMenuDialog')
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

	it('draws Next step as a text button, as the DqZaak boards do (r36)', () => {
		// nextcloud-vue 2.163 draws a stage button's own icon; the boards draw none.
		const nextSteps = Object.values(simple.primaryActionByStage)
			.filter((entry) => typeof entry === 'object' && entry.id === 'stage-next-step')
		expect(nextSteps.length).toBe(3)
		for (const entry of nextSteps) {
			expect(entry.icon).toBeUndefined()
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
		expect(quickIds).not.toContain('case-lifecycle-menu')
	})

	it('shows the card for a Woo request in handling, with the open item first', () => {
		const card = resolveNextStep(
			simple.nextStep,
			{
				id: 'w',
				statusRole: 'in-progress',
				assignee: 'pieter',
				isIncomplete: true,
				statusPublicLabel: 'In behandeling',
			},
			simple.stageField,
		)
		// The board's title names the step and the case's public status label,
		// and falls back to the plain title when the case carries no label.
		expect(firstFilled(card.title, { statusPublicLabel: 'In behandeling' })).toBe('What now? Step 2: in behandeling')
		expect(firstFilled(card.title, {})).toBe('What now? Handle the case')
		expect(card.after).toBe('Then: on to step 3, the decision')
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

	it('patches only actions that exist, and leaves none of the 36 unplaced', () => {
		const patched = Object.keys(overlay.configPatch.headerActions)
		for (const id of patched) {
			expect(ids, id).toContain(id)
		}
		// Every action is still there; the simple page orders them as DqZaakMeer lists them.
		// The simple page adds three acts: Add location (DqZaakLocatie),
		// Share with another organisation (DqZaakSamenwerken) and Plan a case
		// meeting (DqSociaalDomein).
		expect([...simple.headerActions.map((action) => action.id)].sort()).toEqual([...ids, 'add-location', 'share-with-organisation', 'plan-case-meeting'].sort())
		expect(simple.headerActions.map((action) => action.id).slice(0, overlay.configOrder.headerActions.length)).toEqual(overlay.configOrder.headerActions)

		const placed = simple.headerActions.map((action) => {
			if (quickIds.includes(action.id)) {
				return 'quick'
			}
			return action.adminOnly ? 'admin' : (action.group ?? 'top')
		})
		const count = (where) => placed.filter((place) => place === where).length
		expect(placed).toHaveLength(36)
		expect(count('quick')).toBe(3)
		expect(count('top')).toBe(1)
		expect(count('Case')).toBe(21)
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
		expect(quickIds).toEqual([
			'send-digital-post',
			'generate-document',
			'log-contact',
		])
	})

	it('puts the admin group last and hides it from a handler, by the library grouping', () => {
		const entries = simple.headerActions
			.filter((action) => !quickIds.includes(action.id))
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
		// The five every case shows; a case type domain adds its own tabs
		// behind a visibleWhen (checked under the side column).
		expect(
			strip.tabs.filter((tab) => !tab.overflow && !tab.visibleWhen).map((tab) => tab.label),
		).toEqual(['Overview', 'Files', 'Contact', 'Tasks', 'Case history'])
		// The order DqZaakMeer lists them in under Acties.
		expect(
			strip.tabs.filter((tab) => tab.overflow).map((tab) => tab.label),
		).toEqual(['Notes', 'People', 'Email', 'Decisions', 'Related', 'Fees and payments', 'Merge into one PDF', 'Archiving', 'Knowledge', 'Publication', 'Review', 'AI oversight'])
		// Proposals is not a fixed overflow tab: it shows while proposals wait
		// (feat/case-proposals-tab), checked in caseProposalsTabInStrip.spec.js.
		expect(strip.tabs.find((tab) => tab.label === 'Proposals').visibleWhen.appInstalled).toBe('portaliq')
		const widgetIds = (content) => content.tabs
			.flatMap((tab) => (Array.isArray(tab.widgetIds) ? tab.widgetIds : [tab.widgetId]))
		// Every panel of the full strip is still on it, except the Data and
		// Communication panels, whose sections became the Overview and Contact
		// stacks' own cards.
		const shown = widgetIds(strip)
		const unfolded = ['case-data-panel', 'case-communication-panel', 'case-work-panel', 'case-people-panel', 'case-related-panel']
		// The Gerelateerd tab holds the related panel's sections as cards (DqZaakGerelateerd).
		const relatedSections = find(original, 'case-related-panel').content.sections.map((section) => section.widget.id)
		const relatedTab = strip.tabs.find((tab) => tab.label === 'Related')
		// The typed related cases lead (DqZaakGerelateerd), then the related panel's sections.
		expect(relatedTab.widgetIds[0]).toBe('case-related-cases-card')
		const sectionCards = relatedTab.widgetIds.slice(1)
		expect(sectionCards.map((id) => id.replace(/-card$/, ''))).toEqual(['case-related', 'case-sub-cases', 'case-objects', 'case-programme'])
		// The object-type link is a link under the objects, as the board draws it.
		expect(sectionCards.map((id) => id.replace(/-card$/, '')).concat('case-object-types-link').sort()).toEqual([...relatedSections].sort())
		const objectsCard = simple.widgets.find((widget) => widget.id === 'case-objects-card')
		expect(objectsCard.content.footerLinks[0]).toMatchObject({ label: 'Manage object types', group: 'admin' })
		// The Besluiten tab: the decisions, then the decision letter and the advice on the case (DqZaakBesluiten).
		expect(strip.tabs.find((tab) => tab.label === 'Decisions').widgetIds).toEqual(['case-decisions-panel', 'case-decision-letter', 'case-advice-requests'])
		const advice = simple.widgets.find((widget) => widget.id === 'case-advice-requests')
		expect(advice.content).toMatchObject({ register: 'dossiq', schema: 'adviesAanvraag', filter: { case: '@objectId' } })
		// The Betrokkenen tab holds the people panel's sections as cards (DqZaakBetrokkenen).
		const peopleSections = find(original, 'case-people-panel').content.sections.map((section) => section.widget.id)
		const peopleTab = strip.tabs.find((tab) => tab.label === 'People')
		expect(peopleTab.widgetIds.map((id) => id.replace(/-card$/, '')).sort()).toEqual([...peopleSections].sort())
		// The people by role lead, as Partijen; the party model follows as Rollen (DqZaakBetrokkenen).
		expect(peopleTab.widgetIds[0]).toBe('case-parties-card')
		expect(simple.widgets.find((widget) => widget.id === 'case-parties-card').title).toBe('Case parties')
		expect(simple.widgets.find((widget) => widget.id === 'case-party-roles-card').title).toBe('Roles')
		// Followers are the header popover only (Ruben, 20 Oct 2026), so the seats have the row to themselves.
		expect(peopleTab.layout).toBeUndefined()
		expect(peopleTab.widgetIds).not.toContain('case-followers-card')
		for (const id of widgetIds(tabsOf(original)).filter((id) => !unfolded.includes(id))) {
			expect(shown, id).toContain(id)
		}
		const communication = find(original, 'case-communication-panel').content.sections.map((section) => section.widget.id)
		const contactCards = {
			'case-communication': 'case-contact-moments-card',
			'case-acknowledgement': 'case-acknowledgement-card',
			'case-conversations': 'case-conversations-card',
			'linked-contact-moments': 'case-customer-record-card',
		}
		expect(Object.keys(contactCards).sort()).toEqual([...communication].sort())
		const contactTab = strip.tabs.find((tab) => tab.label === 'Contact')
		// DqZaakContact opens with the thread with the resident, over the contact moments.
		expect(contactTab.widgetIds).toEqual(['case-resident-thread', ...Object.values(contactCards)])
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

	it('ends no tab in untitled blocks: fees, contract, family plan and PDF merge are cards of a tab', () => {
		// They stood as grid cells under the tab card, so every tab ended in
		// them (DqZaak, DqSociaalDomein, DqDocumentenBeheer draw none there).
		const strip = tabsOf(simple)
		const fees = strip.tabs.find((tab) => tab.label === 'Fees and payments')
		expect(fees.overflow).toBe(true)
		expect(fees.widgetIds).toEqual(['case-payment-requests', 'case-contract'])
		expect(strip.tabs.find((tab) => tab.label === 'Merge into one PDF').widgetId).toBe('case-merge-to-pdf')
		const overview = strip.tabs.find((tab) => tab.label === 'Overview').widgetIds
		expect(overview.indexOf('goal-plan')).toBe(overview.indexOf('case-household-cases-card') + 1)
		// The family plan keeps its own condition: only where a plan applies.
		expect(find(simple, 'goal-plan').visibleWhen).toEqual(find(original, 'goal-plan').visibleWhen)
		expect(find(simple, 'goal-plan').visibleWhen).toBeDefined()
		// The banner stack ends the side column as the board's Status card.
		expect(find(simple, 'case-banner-stack').title).toBe('Status')
		expect(simple.sideColumn.at(-1)).toBe('case-banner-stack')
		for (const id of ['case-payment-requests', 'case-contract', 'goal-plan', 'case-merge-to-pdf']) {
			expect(simple.layout.some((cell) => cell.widgetId === id), id).toBe(false)
		}
	})

	it('shows the case\'s tasks as the library task table (DqZaakTaken)', () => {
		const tasks = simple.widgets.find((widget) => widget.id === 'case-tasks-card')
		expect(tasks.type).toBe('tasks')
		expect(tasks.content).toMatchObject({
			variant: 'table',
			objectUuid: '@objectId',
			includeDone: true,
			create: { appId: 'dossiq' },
			completeAny: true,
			cancel: true,
			rowRoute: 'TaskDetail',
			viewAllRoute: { name: 'Tasks', query: { case: '@objectId' } },
		})
		// Status tones as DqZaakTaken draws them: in progress purple, open amber,
		// done grey (nextcloud-vue tasks-state-variants).
		expect(tasks.content.stateVariants).toEqual({ available: 'warning', enabled: 'warning', active: 'purple', completed: 'default' })
	})

	it('turns the stages horizontal and leaves every other widget of the page as it was', () => {
		const find = (config, id) =>
			config.widgets.find((widget) => widget.id === id)
		// The design draws the case's progress as a bar across the header
		// card. The widget keeps everything but its orientation.
		// It stands aside beside AI oversight, as the what-now card does.
		expect(find(simple, 'case-stages')).toEqual({
			...find(original, 'case-stages'),
			notTabs: ['ai-oversight'],
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
					offPathField: 'offPath',
					limit: 50,
				},
				datesSource: {
					url: '/apps/dossiq/api/case-types/@object.caseType/blueprint?case=@objectId',
					path: 'statusTypes',
					idField: 'id',
					dateField: 'reachedAt',
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
		// The banner stack is titled Status, as the side card DqZaak draws.
		const patched = ['case-panels', 'case-stages', 'case-tile-deadline', 'case-terms', 'case-flow-runs', 'case-kpis-hours', 'case-email-panel', 'case-status-declaration', 'case-banner-stack', 'case-attention', 'case-archival-panel', 'case-files', 'case-timeline-panel', 'case-notes-panel']
		const added = ['case-note-card', 'case-investigation-steps', 'case-request', 'case-details', 'case-priority-card', 'case-locations-card', 'case-plan-card', 'case-goals', 'case-publication', 'case-contact-moments-card', 'case-acknowledgement-card', 'case-conversations-card', 'case-customer-record-card', 'case-resident-thread', 'case-tasks-card', 'case-planned-actions-card', 'case-incidents-card', 'case-calendar-card', 'case-deck-card', 'case-parties-card', 'case-party-roles-card', 'case-seats-card', 'case-objection-hearings', 'case-objection-advice', 'case-objection-decision', 'case-social-indications', 'case-social-meetings', 'case-tender-bids', 'case-objection-grounds', 'case-objection-advice-card', 'case-grant-decisions', 'case-grant-advances', 'case-related-card', 'case-sub-cases-card', 'case-objects-card', 'case-programme-card', 'case-object-types-link-card', 'case-decision-letter', 'case-advice-requests', 'case-ai-activity', 'case-macros', 'case-assistant-chat', 'case-related-cases-card', 'case-household-cases-card', 'case-document-verdicts-card', 'case-refusal-grounds-card']
		const others = (config) =>
			config.widgets.filter((widget) => !patched.includes(widget.id) && !added.includes(widget.id))
		added.push('case-summary')
		added.push('case-grant-decisions-card')
		expect(others(simple)).toEqual(others(original))
		expect(find(simple, 'case-banner-stack')).toEqual({ ...find(original, 'case-banner-stack'), title: 'Status', _note: expect.any(String) })
		// Its attention strip asks the reason behind one button, with the board's hint.
		const attention = find(original, 'case-attention')
		expect(find(simple, 'case-attention')).toEqual({
			...attention,
			_note: expect.any(String),
			content: { ...attention.content, behindButton: true, labels: { ...attention.content.labels, hintRaise: 'Flag the case with a reason. It then comes first under Needs attention, in All cases.' } },
		})
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
		// DqZaak draws them, so they keep no grid cell either. The Linked card
		// is in the side column, so its full-profile cell goes too.
		// The fees, the contract, the family plan and the PDF merge are cards
		// of tabs, so they keep no cell under the tab card either.
		const gone = ['kpi-1', 'kpi-2', 'kpi-5', '3', 'case-banners', '12', '11', '7', '6', 'full-linked', 'money-payments', 'money-contract', 'goal-plan-panel', 'documents-merge-to-pdf']
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
		const PAIRED = {}
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
		// The pill above the title names the case type, as DqZaak draws "Woo-verzoek",
		// in the case type's own colour and, as the board draws it, without an icon.
		expect(simple.typePill).toEqual({
			field: 'caseType',
			resolve: { register: 'dossiq', schema: 'caseType', labelField: 'title', colorField: 'colour' },
		})
		// The status pill names the status and takes its status type's tone ("In behandeling" purple).
		expect(simple.statusPill).toEqual({
			field: 'status',
			resolve: { register: 'dossiq', schema: 'statusType', labelField: 'name', colorField: 'colour' },
		})
		// The advice notice opens the column while advice is still asked; the deadline is the first card.
		expect(simple.sideColumn[0].id).toBe('side-advice-requested')
		expect(simple.sideColumn[1]).toBe('case-tile-deadline')
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
		expect(onTheCase(simple.typePill.field)).toBe(true)
		// The cards every case shows; the case-type cards are gated and checked below.
		const cards = simple.sideColumn.filter((card) => typeof card === 'object' && !card.visibleWhen && card.type !== 'banner')
		expect(cards.map((card) => card.title)).toEqual(['Requester', 'Handling', 'Linked', 'Work instruction'])
		const shown = []
		for (const card of cards) {
			// The requester is a facts card (a lead, as DqZaak draws it), the
			// handling a read-only data card, the work instruction a facts card
			// over the case TYPE (a link only, no fields of the case).
			const fields = card.type === 'facts'
				? (card.content.fields || []).flatMap((entry) => (entry.as === 'lead' ? [entry.title, entry.subtitle?.path, ...(entry.subtitle?.firstOf || []).map((option) => option.path)] : [entry.path])).filter(Boolean)
				: card.type === 'data' ? card.content.include : []
			if (card.type === 'data') {
				expect(card.content.editable).toBe(false)
			}
			for (const field of fields) {
				expect(onTheCase(field), `${card.title}: ${field}`).toBe(true)
				shown.push(field)
			}
		}
		// The handler reads as a display name, not a user id (DqZaak Behandeling).
		const handling = cards.find((card) => card.id === 'side-handling')
		expect(handling.type).toBe('facts')
		expect(handling.content.fields.find((field) => field.path === 'assignee').format).toBe('user')
		// The requester card names the requester, how they logged in, else their
		// kind; the intake channel is not on it, as the board draws it.
		expect(shown).toEqual(expect.arrayContaining(['initiatorDisplayName', 'initiatorType', 'intakeIdentification']))
		expect(shown).not.toContain('intakeChannel')
		// The deadline has its own card, so no data card repeats it.
		expect(shown).not.toContain('deadline')
	})

	it('shows a domain card only for its case type category, over records linked to this case', () => {
		// DqBezwaar, DqSubsidie, DqSociaalDomein, DqAanbesteding: one generic
		// case page, with side cards gated on the case type's category. Each
		// card lists records that name this case, so no card states a fact
		// the register does not hold.
		// The special-category warnings are gated on the case type itself
		// (caseSpecialDataNotice.spec.js), not on records linked to the case,
		// and so is the case history card those case types show.
		const gated = simple.sideColumn.filter((card) => typeof card === 'object' && card.visibleWhen && card.type !== 'banner' && card.type !== 'audit-trail')
		expect(gated.map((card) => card.id)).toEqual([
			'side-objection-appeal',
			'side-grant-application',
			'side-social-consent',
			'side-social-people',
			'side-tender-bids',
		])
		for (const card of gated) {
			// A list of those records, or (Consent) the facts of the newest one.
			expect(['record-list', 'facts']).toContain(card.type)
			const filter = card.type === 'facts' ? card.content.source.filter : card.content.filter
			expect(Object.values(filter)).toEqual(['@objectId'])
			expect(JSON.stringify(card.visibleWhen)).toContain('@context.caseType.category')
		}
	})

	it('adds the tabs of a case type domain only for that category, each over records linked to this case', () => {
		const find = (config, id) => config.widgets.find((widget) => widget.id === id)
		const tabs = find(simple, 'case-panels').content.tabs
		// Proposals is gated on waiting proposals, not on a case type domain.
		// Investigation lists the case's own answer (investigationStepsTab.spec.js), not linked records.
		const gated = tabs.filter((tab) => tab.visibleWhen && tab.widgetId !== 'case-change-proposals' && tab.widgetId !== 'case-investigation-steps')
		expect(gated.map((tab) => tab.widgetId)).toEqual([
			'case-objection-hearings',
			'case-objection-advice',
			'case-objection-decision',
			'case-social-indications',
			'case-social-meetings',
			'case-tender-bids',
			'case-grant-decisions',
			'case-grant-advances',
		])
		expect(tabs[0].label).toBe('Overview')
		// The objection's grounds open the Overview stack, gated by the card's own visibleWhen.
		expect(tabs[0].widgetIds[0]).toBe('case-objection-grounds')
		const grounds = find(simple, 'case-objection-grounds')
		expect(JSON.stringify(grounds.visibleWhen)).toContain('Bezwaar en beroep')
		expect(grounds.content.source.url).toContain('case=@objectId')
		// The committee's advice follows the grounds, as DqBezwaar draws it; the Advice tab lists every report.
		expect(tabs[0].widgetIds[1]).toBe('case-objection-advice-card')
		const advice = find(simple, 'case-objection-advice-card')
		expect(JSON.stringify(advice.visibleWhen)).toContain('Bezwaar en beroep')
		expect(advice.content.source.url).toContain('advisoryReport?case=@objectId')
		for (const tab of gated) {
			const list = find(simple, tab.widgetId)
			expect(list.type).toBe('object-list')
			expect(Object.values(list.content.filter)).toEqual(['@objectId'])
		}
		// Subsidy records name the application, not the case: they are reached
		// through it with a flat `_referenced` key, never a dotted path.
		expect(Object.keys(find(simple, 'case-grant-decisions').content.filter)).toEqual(['_referenced[subsidieAanvraag][subsidieaanvraag][case]'])
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

describe('the assistant in the case page (DqZaakAssistent)', () => {
	const layout = JSON.parse(fs.readFileSync(path.join(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
	const page = layout.pages.find((entry) => entry.id === 'CaseDetail')
	const widget = page.configAppend.widgets.find((entry) => entry.id === 'case-assistant-chat')
	const tab = page.configPatch.widgets['case-panels'].content.tabs.find((entry) => entry.label === 'AI oversight')
	const byId = (id) => page.configAppend.widgets.find((entry) => entry.id === id)

	it('places the AI chat the launcher opens, embedded, needing hermiq', () => {
		expect(widget).toMatchObject({ type: 'ai-chat', requiredApp: 'hermiq', content: { chatAppId: 'hermiq' } })
	})

	it('gives it the AI oversight tab, under Acties until it is opened', () => {
		expect(tab).toMatchObject({ label: 'AI oversight', overflow: true })
	})

	it('stacks what the assistant did and the macros in that tab, as the board does', () => {
		expect(tab.id).toBe('ai-oversight')
		expect(tab.widgetIds).toEqual(['case-ai-activity', 'case-macros'])
	})

	it('stands the four generic side cards aside on that tab, so the chat is alone as the board draws it', () => {
		const side = page.config.sideColumn.filter((entry) => typeof entry === 'object')
		for (const id of ['side-requester', 'side-handling', 'side-linked', 'side-work-instruction']) {
			expect(side.find((entry) => entry.id === id).notTabs).toEqual(['ai-oversight'])
		}
		expect(page.configPatch.widgets['case-tile-deadline'].notTabs).toEqual(['ai-oversight'])
	})

	it('puts the chat in the side column, after the generic cards, while that tab is open', () => {
		const ids = page.config.sideColumn.map((entry) => (typeof entry === 'string' ? entry : entry.id))
		expect(ids.indexOf('case-assistant-chat')).toBe(ids.indexOf('side-work-instruction') + 1)
		expect(widget.tabs).toEqual(['ai-oversight'])
	})

	it('lists hermiq\'s oversight records of this case through hermiq, needing hermiq', () => {
		const activity = byId('case-ai-activity')
		expect(activity).toMatchObject({ type: 'object-list', requiredApp: 'hermiq', subtitle: 'Every suggestion is recorded, also when nobody uses it' })
		expect(activity.content.endpointSource).toEqual({
			url: '/apps/hermiq/api/ai-oversight/subject',
			params: { originApp: 'dossiq', subjectId: '@objectId', limit: 25 },
		})
		expect(activity.content.register).toBeUndefined()
		expect(activity.content.columns.map((column) => column.key)).toEqual(['decidedAt', 'decidedBy', 'prompt', 'status', 'advisoryContext.model'])
		expect(activity.content.columns[3].enumLabels).toEqual({ approved: 'Used', overridden: 'Adapted', unused: 'Not used', denied: 'Rejected' })
	})

	it('places the case schema\'s macros through the library macros widget', () => {
		expect(byId('case-macros')).toMatchObject({ type: 'macros', title: 'Macros' })
		expect(byId('case-macros').content.macrosFrom).toBe('@context.caseType.macros')
	})

	it('draws the Macro\'s line as the card subtitle, as DqZaakAssistent', () => {
		expect(byId('case-macros').subtitle).toBe('Several steps at once; then you see what changed')
		expect(byId('case-macros').content.description).toBeUndefined()
	})
})
