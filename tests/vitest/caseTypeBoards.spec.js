/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type page as boards DqZaaktype, DqZaaktypeOverzicht and
 * DqZaaktypeVelden draw it, in the simple structure profile: the tab strip,
 * the four cards of Phases and tasks, the workflow canvas on a page of its
 * own, the variants, the terms in words and the side facts as labels.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const built = buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, readJson('src', 'menu-layout.simple.json'))
const page = (id) => built.pages.find((item) => item.id === id)
const detail = page('CaseTypeDetail')
const widget = (id) => detail.config.widgets.find((item) => item.id === id)
const tabs = widget('case-type-panels').content.tabs
const tab = (id) => tabs.find((item) => item.id === id)
const idsOf = (entry) => entry.widgetIds || [entry.widgetId]

describe('the case type tab strip (REQ-CTB-1)', () => {
	it('reads as the board', () => {
		expect(tabs.filter((entry) => !entry.overflow).map((entry) => entry.id)).toEqual([
			'overview', 'fields', 'statuses', 'phases', 'rules', 'rights', 'privacy', 'translations', 'versions', 'history',
		])
	})

	it('keeps Portal under the Actions menu', () => {
		expect(tab('portal').overflow).toBe(true)
		expect(idsOf(tab('portal'))).toEqual(['case-type-portal', 'case-type-party-kinds'])
	})

	it('gives the case rules a tab of their own', () => {
		expect(tab('rules').label).toBe('Rules')
		expect(idsOf(tab('rules'))).toContain('case-type-rules')
		expect(idsOf(tab('fields'))).not.toContain('case-type-rules')
	})
})

describe('Phases and tasks (REQ-CTB-2)', () => {
	it('holds the board\'s four cards, in order', () => {
		expect(idsOf(tab('phases'))).toEqual([
			'case-type-phase-board', 'case-type-variants', 'case-type-terms-written', 'case-type-messages',
		])
	})

	it('keeps every part it moved out reachable on the Statuses tab', () => {
		expect(idsOf(tab('statuses'))).toEqual(expect.arrayContaining([
			'case-type-blueprint', 'case-type-document-types', 'case-type-decision-types',
		]))
	})

	it('opens the workflow canvas on its own page from the buttons under the card\'s subtitle (DqZaaktype)', () => {
		const board = widget('case-type-phase-board')
		expect(board.headerLink).toBeUndefined()
		expect(board.content.groupAction).toBeUndefined()
		expect(board.content.actions.map((action) => action.label)).toEqual(['View as flow', 'Add a task'])
		board.content.actions.forEach((action) => {
			expect(action).toEqual(expect.objectContaining({ type: 'navigate', target: '/settings/case-types/@objectId/flow' }))
		})
		const flow = page('CaseTypeFlow')
		expect(flow.route).toBe('/settings/case-types/:id/flow')
		expect(flow.type).toBe('custom')
		const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
		expect(registry).toMatch(new RegExp(`${flow.component}: \\{\\s*kind: 'page'`))
	})

	it('no tab draws the canvas inline any more', () => {
		for (const entry of tabs) {
			expect(idsOf(entry), entry.id).not.toContain('case-type-workflow-editor')
		}
	})
})

describe('variants (REQ-CTB-3)', () => {
	it('are a field of the case type', () => {
		const schema = readJson('lib', 'Settings', 'register.d', '68-case-type-variants.json').components.schemas.caseType
		expect(schema.properties.variants.type).toBe('array')
		expect(Object.keys(schema.properties.variants.items.properties)).toEqual(['name', 'description', 'isDefault'])
	})

	it('are listed with the default marked', () => {
		const variants = widget('case-type-variants')
		expect(variants.type).toBe('field-list')
		expect(variants.content.field).toBe('variants')
		expect(variants.content.row.pill.map).toEqual({ true: 'Default', false: 'Rule' })
	})
})

describe('terms in words (REQ-CTB-4)', () => {
	it('read the terms endpoint', () => {
		const terms = widget('case-type-terms-written')
		expect(terms.type).toBe('facts')
		expect(terms.content.source).toEqual({ url: '/apps/dossiq/api/case-types/@objectId/terms' })
		expect(terms.content.fields.map((field) => field.path)).toEqual(['decisionTerm', 'penalty', 'suspension', 'serviceTarget'])
	})

	it('have a route', () => {
		expect(fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8'))
			.toContain("'url' => '/api/case-types/{id}/terms'")
	})
})

describe('the side facts (REQ-CTB-5)', () => {
	const facts = detail.config.sideColumn.find((card) => card.id === 'side-case-type-facts').content.fields

	it('read as labels, without raw codes', () => {
		const paths = facts.map((field) => field.path).filter(Boolean)
		expect(paths).not.toContain('handlingModel')
		expect(paths).not.toContain('confidentiality')
		expect(facts.map((field) => field.label)).toEqual(['Domain', 'Origin', 'Origin', 'Form', 'Handling teams', 'Running cases'])
	})

	it('name a shipped case type as shipped', () => {
		const origin = facts.filter((field) => field.label === 'Origin')
		expect(origin.map((field) => field.when)).toEqual([
			{ field: '@self.owner', op: 'eq', value: '__system__' },
			{ field: '@self.owner', op: 'neq', value: '__system__' },
		])
		expect(origin.map((field) => field.parts[0].text)).toEqual(['Shipped', 'Own case type'])
	})
})

describe('card header actions (nextcloud-vue widget-header-actions)', () => {
	it.each([
		['case-type-statuses', 'Edit', 'statuses'],
		['case-type-rules-summary', 'Add a rule', 'rules'],
		['case-type-terms-written', 'Edit', 'properties'],
	])('%s carries %s, opening the %s tab', (id, label, tabId) => {
		expect(widget(id).headerActions).toEqual([
			expect.objectContaining({ label, action: { type: 'navigate', target: `/settings/case-types/@objectId#${tabId}` } }),
		])
		expect(tab(tabId)).toBeTruthy()
	})

	it('keeps the raw terms editable on the Properties tab', () => {
		expect(idsOf(tab('properties'))).toContain('case-type-terms')
	})
})

describe('the side column (nextcloud-vue 24p)', () => {
	const side = (id) => detail.config.sideColumn.find((card) => card.id === id)

	it('titles the data card as a side card', () => {
		expect(side('side-case-type-facts').titleTone).toBe('side')
	})

	it('shows what changed in the history card, not who read it, and opens the History tab', () => {
		const history = side('side-case-type-history')
		expect(history.title).toBe('Record history')
		expect(history.content.excludeActions).toEqual(['read'])
		expect(history.headerLink).toEqual({ label: 'All history', tab: 'history' })
	})
})

describe('the choice list beside Fields (DqZaaktypeVelden, nextcloud-vue side-card-follows-row)', () => {
	const card = detail.config.sideColumn.find((entry) => entry.id === 'side-case-type-choice-list')
	const fieldsEditor = detail.config.widgets.find((entry) => entry.id === 'case-type-fields-editor')

	it('lets the Fields table select a field', () => {
		expect(fieldsEditor.content.rowSelection).toBe(true)
	})

	it('follows the selected field that has a choice list, beside the Fields tab', () => {
		expect(card.tabs).toEqual(['fields'])
		expect(card.title).toBe('Choice list: {name|fieldTitle}')
		expect(card.follows).toEqual({
			widget: 'case-type-fields-editor',
			register: 'dossiq',
			schema: 'propertyDefinition',
			when: { field: 'enumValues', op: 'notEmpty' },
		})
	})

	it('lists the options and adds one as a plain value', () => {
		expect(card.type).toBe('field-list')
		expect(card.content.field).toBe('enumValues')
		expect(card.content.add).toEqual(expect.objectContaining({ label: 'Add option', scalar: true, unique: true }))
		expect(card.content.add.fields).toEqual([{ key: 'value', label: 'Option', required: true }])
	})

	it('sits above the parent type card', () => {
		const ids = detail.config.sideColumn.map((entry) => entry.id)
		expect(ids.indexOf('side-case-type-choice-list')).toBeLessThan(ids.indexOf('side-case-type-parent'))
	})
})
