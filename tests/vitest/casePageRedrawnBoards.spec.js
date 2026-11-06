// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page cards the redrawn boards of 3 November add: the family plan's
 * Doel toevoegen and its seeded goals (DqSociaalDomein), Volledigheid van de
 * aanvraag (DqZaakDocumenten), Labels and Toegang (DqZaak), the milestone note
 * (DqZaakTaken), Projecten and the relation line (DqZaakGerelateerd), Soort en
 * actie (DqZaakAssistent) and the archive marking (DqZaakArchivering).
 */

import { onTab } from '@conduction/nextcloud-vue/src/utils/detailActionModel.js'
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseDetail')
const widget = (id) => page.configAppend.widgets.find((entry) => entry.id === id)
const tabs = page.configPatch.widgets['case-panels'].content.tabs
const tab = (label) => tabs.find((entry) => entry.label === label)
const examples = (file) => read(`lib/Settings/packages/jeugdwet/examples/${file}`)

describe('the family plan (DqSociaalDomein)', () => {
	const plan = page.configPatch.widgets['goal-plan']

	it('adds a goal to the plan of this case from the card header', () => {
		const add = plan.headerActions[0]
		expect(add.label).toBe('Add goal')
		const config = add.action.props.config
		expect(config.summary.source.url).toBe('/apps/dossiq/api/cases/@objectId/family-plan')
		expect(config.confirm.request).toEqual({ method: 'POST', url: '/apps/dossiq/api/family-plans/@object.planId/goals' })
		expect(config.fields.map((field) => field.field)).toEqual(['title', 'metWhen'])
		expect(config.fields.every((field) => field.required)).toBe(true)
	})

	it('seeds two goals and three interventions on the example plan, each tied to its goal', () => {
		const planId = examples('15-family-plan.json').rows[0].id
		const goals = examples('16-family-plan-goals.json')
		const interventions = examples('17-family-plan-interventions.json')
		expect(goals.schema.slug).toBe('casePlanGoal')
		expect(interventions.schema.slug).toBe('intervention')
		expect(goals.rows.map((goal) => goal.plan)).toEqual([planId, planId])
		expect(goals.rows.map((goal) => goal.state)).toEqual(['open', 'met'])
		const goalIds = goals.rows.map((goal) => goal.id)
		expect(interventions.rows).toHaveLength(3)
		for (const intervention of interventions.rows) {
			expect(intervention.plan).toBe(planId)
			expect(goalIds).toContain(intervention.goal)
		}
		expect(interventions.rows.map((row) => row.state)).toEqual(['planned', 'running', 'complete'])
	})
})

describe('the completeness of the application (DqZaakDocumenten)', () => {
	const card = widget('case-completeness-card')

	it('stands between the files and the refusal grounds', () => {
		expect(tab('Files').widgetIds).toEqual(['case-files', 'case-completeness-card', 'case-refusal-grounds-card'])
	})

	it('reads the list from the endpoint its visibility asks, so it stays hidden until that answers', () => {
		expect(card.content.endpointSource.url).toBe('/apps/dossiq/api/cases/@objectId/completeness')
		expect(card.visibleWhen).toEqual({ endpoint: card.content.endpointSource.url, field: 'items', op: 'notEmpty' })
		expect(card.content.presentation).toBe('checklist')
		expect(evaluateVisibleWhenLocal(card.content.done, { present: true })).toBe(true)
		expect(evaluateVisibleWhenLocal(card.content.done, { present: false })).toBe(false)
	})

	it('confirms admissibility through the intake verdict route', () => {
		const request = card.content.footerAction.props.config.confirm.request
		expect(request).toEqual({ method: 'POST', url: '/apps/dossiq/api/cases/@objectId/admissibility', body: { verdict: 'admissible' } })
	})
})

describe('the case overview (DqZaak)', () => {
	it('lists the labels last among the case details, editable as free words', () => {
		const details = widget('case-details').content
		expect(details.include[details.include.length - 1]).toBe('tags')
		expect(details.overrides.tags).toMatchObject({ label: 'Labels', widget: 'tags' })
	})

	it('shows Toegang beside the Overview tab only', () => {
		expect(tabs[0]).toMatchObject({ id: 'overview', label: 'Overview' })
		const access = page.config.sideColumn.find((entry) => typeof entry === 'object' && entry.id === 'side-access')
		expect(access.content.endpointSource.url).toBe('/apps/openregister/api/objects/dossiq/case/@objectId/grants')
		expect(onTab(access, 'overview')).toBe(true)
		expect(onTab(access, 'ai-oversight')).toBe(false)
	})
})

describe('the case tabs', () => {
	it('notes under What comes next how milestones count (DqZaakTaken)', () => {
		const planned = widget('case-planned-actions-card').content
		expect(planned.footnoteVariant).toBe('info')
		expect(planned.footnote).toMatch(/^A milestone that depends on another part/)
	})

	it('places planninq\'s projects after the sub-cases and names the relation of a linked case (DqZaakGerelateerd)', () => {
		const related = tab('Related').widgetIds
		expect(related.indexOf('case-projects-card')).toBe(related.indexOf('case-sub-cases-card') + 1)
		expect(widget('case-projects-card')).toMatchObject({ type: 'integration', integrationId: 'planninq-projects' })
		const cases = widget('case-related-cases-card').content
		expect(cases.text[0]).toBe('Relation: {aardRelatie} (RGBZ). Note: {notes}')
		expect(cases.valueLabels.aardRelatie.bijdrage).toBe('contribution')
	})

	it('names the kind of each assistant suggestion (DqZaakAssistent)', () => {
		const columns = widget('case-ai-activity').content.columns
		expect(columns[2]).toMatchObject({ key: 'advisoryContext.suggestionType', label: 'Kind and action' })
		expect(columns[2].secondary).toBe('advisoryContext.action')
		expect(columns[2].enumLabels['refusal-ground']).toBe('Classification')
	})
})

describe('the archive marking (DqZaakArchivering)', () => {
	const banner = widget('case-archive-mismatch')

	it('closes the Archiving tab with the marking and the disagreement', () => {
		expect(tab('Archiving').widgetIds.slice(-2)).toEqual(['case-archive-marking-card', 'case-archive-mismatch'])
		const paths = widget('case-archive-marking-card').content.fields.map((field) => field.path)
		expect(paths).toEqual(['@self.archived.at', '@self.archived.by', '@self.archived.reason'])
	})

	it('warns only when the case and OpenRegister disagree, in either direction', () => {
		const marking = { at: '2027-03-12T09:40:00Z', by: 'marloes.bakker', reason: 'Bewaartermijn verstreken' }
		const shows = (record) => evaluateVisibleWhenLocal(banner.visibleWhen, record)
		expect(shows({ archiveStatus: 'archived', '@self': { archived: [] } })).toBe(true)
		expect(shows({ archiveStatus: 'nog_te_archiveren', '@self': { archived: marking } })).toBe(true)
		expect(shows({ archiveStatus: 'archived', '@self': { archived: marking } })).toBe(false)
		expect(shows({ archiveStatus: 'nog_te_archiveren', '@self': { archived: [] } })).toBe(false)
	})
})
