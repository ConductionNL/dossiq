// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case page's record widgets are library widgets, declared in the
 * manifest (DQ-ABSTRACT lane L4-RECORD, Ruben's rule of 20 Oct 2026: pages are
 * manifest config over abstract, configurable nextcloud-vue widgets).
 *
 * What each widget DOES is tested where it now lives, in nextcloud-vue
 * (CnAttentionFlagWidget, CnStatusExplainerWidget, CnObjectPartiesWidget,
 * CnBannerWidget, CnMapWidget, CnRecordListWidget, CnGroupedCardsWidget,
 * CnRelatedObjectsWidget, CnFactsWidget, CnNotesTab, CnRedactionEditor).
 * This file holds dossiq to its half: that the page declares them, with the
 * endpoints, words and ids its users depend on, in both structure profiles.
 *
 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 * @spec openspec/specs/status-transition-engine/spec.md
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'
import { pageWidgets } from './helpers/pageViews.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const exists = (...parts) => fs.existsSync(path.join(ROOT, ...parts))
const manifest = readJson('src', 'manifest.json')
const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const simple = buildProfiledManifest(buildManifest, manifest, fragments, readJson('src', 'menu-layout.simple.json'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const fullCase = manifest.pages.find((page) => page.id === 'CaseDetail')
const simpleCase = simple.pages.find((page) => page.id === 'CaseDetail')

/**
 * Every widget definition on a page, the ones inside a sections widget and
 * the side column too.
 *
 * @param {object} page The page.
 * @return {Array<object>} The definitions.
 */
function allWidgets(page) {
	const out = []
	const visit = (widget) => {
		if (!widget || typeof widget !== 'object') {
			return
		}
		out.push(widget)
		for (const section of widget.content?.sections || []) {
			visit(section.widget)
		}
	}
	pageWidgets(page).forEach(visit)
	// The side column holds definitions of its own, beside ids of the page's.
	;(page.config?.sideColumn || []).forEach(visit)
	return out
}

const find = (page, id) => allWidgets(page).find((widget) => widget.id === id)

const DELETED = [
	['src', 'components', 'case', 'CaseBannerStack.vue'],
	['src', 'components', 'case', 'CaseArchivedStrip.vue'],
	['src', 'components', 'case', 'CaseUnreadPanel.vue'],
	['src', 'components', 'case', 'CaseAttentionPanel.vue'],
	['src', 'components', 'case', 'CaseStatusDeclarationPanel.vue'],
	['src', 'components', 'case', 'CaseFollowersPanel.vue'],
	['src', 'components', 'case', 'CaseLocationMap.vue'],
	['src', 'components', 'case', 'CasePartiesWidget.vue'],
	['src', 'components', 'case', 'CasePipelinqContactMoments.vue'],
	['src', 'components', 'case', 'CasePlanSociaalDomeinPanel.vue'],
	['src', 'components', 'case', 'CasePlannedWidget.vue'],
	['src', 'components', 'case', 'CaseProgrammeSection.vue'],
	['src', 'components', 'case', 'CaseWorkInstructionPanel.vue'],
	['src', 'views', 'cases', 'components', 'CaseNotesTab.vue'],
	['src', 'views', 'cases', 'CaseRedactionView.vue'],
	['src', 'views', 'widgets', 'FirstTodayWidget.vue'],
	['src', 'utils', 'statusDeclaration.js'],
	['src', 'services', 'caseAttentionApi.js'],
]

describe('the dossiq copies are gone', () => {
	it.each(DELETED.map((parts) => [parts.join('/'), parts]))('%s', (_name, parts) => {
		expect(exists(...parts)).toBe(false)
	})

	it('registers none of their keys any more', () => {
		for (const key of ["'case-banner-stack'", "'case-archived'", "'case-unread'", "'case-attention'", "'case-status-declaration'", "'case-followers'", "'case-location-map'", "'case-party-roles'", "'case-pipelinq-contact-moments'", "'family-plan'", "'case-related-planned'", "'case-programme'", "'case-work-instruction'", "'case-notes-pane'", 'CaseNotesTab:', 'CaseRedactionView:', 'FirstTodayWidget:']) {
			expect(registrySource).not.toContain(`${key} {`)
		}
	})

	it('registers the dialogs the library widgets open', () => {
		expect(registrySource).toContain('FileContactMomentDialog: {')
		expect(registrySource).toContain('LinkProgrammeDialog: {')
		expect(registrySource).toContain('CasePlanFollowUpDialog: {')
	})
})

describe('the banner row', () => {
	const stack = find(fullCase, 'case-banner-stack')

	it('is one size-to-content row of three strips, above the panels', () => {
		expect(stack.type).toBe('sections')
		expect(stack.content.dividers).toBe(false)
		expect(stack.content.sections.map((s) => s.widgetId)).toEqual(['case-archived', 'case-status-declaration', 'case-attention'])
		const row = fullCase.config.layout.find((cell) => cell.widgetId === 'case-banner-stack')
		const panels = fullCase.config.layout.find((cell) => cell.widgetId === 'case-panels')
		expect(row.sizeToContent).toBe(true)
		expect(row.gridY).toBeLessThan(panels.gridY)
	})

	it('says a case is archived from the marker every list excludes on, with the shorter sentence when it names nobody', () => {
		const archived = find(fullCase, 'case-archived')
		expect(archived.type).toBe('banner')
		expect(archived.content.visibleWhen).toEqual({ field: '@self.archived', op: 'notEmpty' })
		expect(archived.content.lead).toContain('{subject.@self.archived.by}')
		expect(archived.content.lead).toContain('{subject.@self.archived.at|date}')
		expect(archived.content.fallbackLead).toBe('This case is archived. You can read it, and changes are refused.')
		expect(archived.content.text).toBe('{subject.@self.archived.reason}')
	})

	it('explains the status from the transition endpoint, in the case words', () => {
		const status = find(fullCase, 'case-status-declaration')
		expect(status.type).toBe('status-explainer')
		expect(status.content.endpointSource.url).toBe('/apps/dossiq/api/case/@objectId/available-transitions')
		expect(status.content.labels.derivationHeading).toBe('This case moves to {status} once these are on file')
		expect(status.content.labels.fieldRulesHeading).toBe('What this status asks of the case')
	})

	it('raises and clears the flag through dossiq, reading the markers and the assessment off the case', () => {
		const attention = find(fullCase, 'case-attention')
		expect(attention.type).toBe('attention-flag')
		expect(attention.content.endpoint).toBe('/apps/dossiq/api/case/@objectId/attention')
		expect(attention.content.markersPath).toBe('attentionMarkers')
		expect(attention.content.assessment.path).toBe('riskAssessment')
		// Every panel a marker may name is a tab the strip carries.
		const tabs = find(fullCase, 'case-panels').content.tabs.map((tab) => tab.widgetId)
		for (const panel of Object.keys(attention.content.panelLabels)) {
			expect(tabs, panel).toContain(panel)
		}
	})

	it('rides the side column of the simple profile too', () => {
		expect(simpleCase.config.sideColumn).toContain('case-banner-stack')
	})
})

describe('what is new since the reader last looked', () => {
	it.each([['full', fullCase], ['simple', simpleCase]])('is counted on the tabs and cleared when one opens (%s)', (_name, page) => {
		const panels = find(page, 'case-panels')
		expect(panels.content.badgeSource).toBe('readState')
		const tabs = panels.content.tabs
		expect(tabs.find((tab) => tab.widgetId === 'case-files' || (tab.widgetIds || []).includes('case-files')).unreadKey).toBe('files')
		expect(tabs.find((tab) => tab.widgetId === 'case-notes-panel').unreadKey).toBe('notes')
	})

	it('leaves Mark as unread and the read stamp to the detail page', () => {
		expect(fullCase.config.markRead).not.toBe(false)
	})
})

describe('followers', () => {
	it.each([['full', fullCase], ['simple', simpleCase]])('are the header popover only, with no section on the page (%s)', (_name, page) => {
		expect(allWidgets(page).some((widget) => widget.type === 'case-followers' || widget.type === 'followers')).toBe(false)
		expect(page.config.follow).not.toBe(false)
	})
})

describe('the location map', () => {
	it.each([['full', fullCase, 'case-location-map'], ['simple', simpleCase, 'case-locations-card']])('plots the case-location objects of this case (%s)', (_name, page, id) => {
		const map = find(page, id)
		expect(map.type).toBe('map')
		expect(map.content.markers.dataSource).toEqual({ register: 'dossiq', schema: 'case-location', filter: { case: '@objectId' }, limit: 100 })
		expect(map.content.emptyText).toBe('No locations linked to this case yet')
		expect(map.content.errorText).toBe('The locations could not be loaded.')
		expect(map.content.refreshOn).toBe('dossiq:case-location:saved')
		expect(map.content.refreshMatch).toBe('caseId')
	})
})

describe('the parties', () => {
	it.each([['full', fullCase, 'case-party-roles'], ['simple', simpleCase, 'case-party-roles-card']])('come from the party model with dossiq\'s words and pipelinq\'s reads (%s)', (_name, page, id) => {
		const parties = find(page, id)
		expect(parties.type).toBe('parties')
		expect(parties.content.rolesObjects).toEqual({ register: 'dossiq', schema: 'role', field: 'case' })
		expect(parties.content.roleLabels.gemachtigde).toBe('Authorised representative')
		expect(parties.content.kindsSource.url).toBe('/apps/dossiq/api/cases/@objectId/pipelinq/party-kinds')
		expect(parties.content.kindsSource.enforce).toBe('pipelinq')
		expect(parties.content.extraLines[0].endpoint).toBe('/apps/dossiq/api/cases/@objectId/pipelinq/parties/{party}/language')
		expect(parties.content.correspondence).toEqual({ endpoint: '/apps/dossiq/api/cases/@objectId/dossier', path: 'informatieobjecten' })
		expect(parties.content.labels.failed).toBe('The parties of this case could not be read')
	})
})

describe('the contact moments in the customer record', () => {
	it.each([['full', fullCase, 'linked-contact-moments'], ['simple', simpleCase, 'case-customer-record-card']])('keep their id, and say absent, empty and failed apart (%s)', (_name, page, id) => {
		const moments = find(page, id)
		expect(moments.type).toBe('record-list')
		expect(moments.content.endpointSource).toEqual({ url: '/apps/dossiq/api/cases/@objectId/pipelinq/contact-moments', responsePath: 'moments' })
		expect(moments.content.requiresApp).toBe('pipelinq')
		expect(moments.content.absentText).toContain('Pipelinq is not installed on this instance')
		expect(moments.content.emptyText).toBe('No contact moments in the customer record for this case yet')
		expect(moments.content.errorText).toBe('The customer record could not be read')
	})

	it('files a moment on another case through the dialog and takes it off through pipelinq', () => {
		const moments = find(fullCase, 'linked-contact-moments')
		const [file, unfile] = moments.content.rowActions
		expect(file).toMatchObject({ type: 'open-modal', target: 'FileContactMomentDialog', props: { caseId: '@objectId' } })
		expect(unfile).toMatchObject({ endpoint: '/apps/dossiq/api/cases/@objectId/pipelinq/contact-moments/{id}', method: 'DELETE' })
		expect(moments.content.rowActionsLabel).toBe('Actions for this contact moment')
		expect(moments.content.links).toMatchObject({ field: 'alsoOnCases', label: 'Also on:', route: { name: 'CaseDetail', params: { id: '{value}' } } })
	})
})

describe('the family plan', () => {
	// Renamed from family-plan; RenamedLayoutIds rewrites stored layouts.
	const plan = find(fullCase, 'goal-plan')

	it('keeps its id and its condition, and reads the plan the case names', () => {
		expect(plan.type).toBe('sections')
		expect(plan.visibleWhen.any[0]).toEqual({ field: 'gezinsplanId', op: 'notEmpty' })
		const [due, goals] = plan.content.sections.map((section) => section.widget)
		expect(due.type).toBe('banner')
		expect(due.content.visibleWhen).toMatchObject({ endpoint: '/apps/dossiq/api/cases/@objectId/family-plan', field: 'dueForReview', value: true })
		expect(goals.type).toBe('grouped-cards')
		expect(goals.content.endpointSource.url).toBe('/apps/dossiq/api/cases/@objectId/family-plan')
	})

	it('puts each intervention under its goal, the loose ones apart, the late ones flagged in words, and fails closed', () => {
		const goals = plan.content.sections[1].widget.content
		expect(goals).toMatchObject({ groupsPath: 'goals', itemsPath: 'interventions', itemGroupField: 'goal', ungroupedTitle: 'Not tied to a goal', columns: 1 })
		expect(goals.itemFlag).toEqual({ field: 'overdue', label: 'Overdue' })
		expect(goals.groupLines).toContain('Met when: {metWhen}')
		expect(goals.errorText).toBe('The family plan could not be read.')
	})

	it('is placed in the layout, so it is not dark', () => {
		expect(fullCase.config.layout.some((cell) => cell.widgetId === 'goal-plan')).toBe(true)
	})
})

describe('the Related cases tab', () => {
	// The simple structure lists the planned follow-ups and the typed
	// relations in case-related-cases-card (typedRelatedCasesCard.spec.js);
	// its case-related-card keeps the parent case (caseRelatedParentOnly.spec.js).
	it('keeps the parent case on the parent card (simple)', () => {
		const related = find(simpleCase, 'case-related-card')
		expect(related.type).toBe('related')
		expect(related.content.extraSources).toBeUndefined()
		expect(related.content.referenceSections[0]).toMatchObject({ field: 'parentCase', register: 'dossiq', schema: 'case' })
	})

	it('reads the planned follow-ups, the typed relations and the parent (full)', () => {
		const related = find(fullCase, 'case-related')
		expect(related.type).toBe('related')
		expect(related.content.showObjects).toBe(false)
		const [planned, relations] = related.content.extraSources
		expect(planned).toMatchObject({ key: 'planned', endpoint: '/apps/dossiq/api/case/@objectId/planned', path: 'results', static: true })
		expect(planned.rowActions[0]).toMatchObject({ label: 'Stop series', endpoint: '/apps/dossiq/api/case/@objectId/planned/{id}/stop' })
		expect(planned.rowActions[0].when).toEqual({ field: 'recurrence', op: 'neq', value: 'none' })
		expect(relations).toMatchObject({ endpoint: '/apps/dossiq/api/cases/@objectId/relations', requireLabel: true })
		expect(related.content.referenceSections[0]).toMatchObject({ field: 'parentCase', register: 'dossiq', schema: 'case' })
		// "Link case" sits before it in the footer, so it is found by its dialog.
		expect(related.content.footerActions.find((action) => action.target === 'CasePlanFollowUpDialog')).toMatchObject({ type: 'open-modal', props: { caseId: '@objectId' } })
	})
})

describe('the programme', () => {
	it.each([['full', fullCase, 'case-programme'], ['simple', simpleCase, 'case-programme-card']])('reads pipelinq through dossiq, only where pipelinq is installed (%s)', (_name, page, id) => {
		const programme = find(page, id)
		expect(programme.type).toBe('facts')
		expect(programme.visibleWhen).toEqual({ appInstalled: 'pipelinq' })
		expect(programme.content.source).toEqual({ url: '/apps/dossiq/api/cases/@objectId/pipelinq/programme', path: 'programme' })
		const progress = programme.content.fields.find((field) => field.as === 'progress')
		expect(progress.barWhen).toEqual({ field: 'progress.computable', op: 'eq', value: true })
	})

	it('hands the link dialog the case and its title (DQ-BUGS: the title was read off the wrong prop)', () => {
		const programme = find(fullCase, 'case-programme')
		expect(programme.content.emptyActions[0]).toMatchObject({ type: 'open-modal', target: 'LinkProgrammeDialog', props: { caseId: '@objectId', caseTitle: '@object.title' } })
	})
})

describe('the work instruction', () => {
	it.each([['full', fullCase, 'case-work-instruction'], ['simple', simpleCase, 'side-work-instruction']])('links the page the case type names, in Collectives, in a new tab (%s)', (_name, page, id) => {
		const instruction = find(page, id)
		expect(instruction.type).toBe('facts')
		expect(instruction.content.source).toEqual({ register: 'dossiq', schema: 'caseType', id: '@object.caseType' })
		expect(instruction.content.link).toMatchObject({ href: '{knowledgeBasePage}', hrefBase: '/apps/collectives/', target: '_blank' })
		expect(instruction.content.fields).toBeUndefined()
	})
})

describe('the Notes tab', () => {
	it('is the library notes tab, with mentions and the push wired to dossiq', () => {
		const notes = find(fullCase, 'case-notes-panel')
		expect(notes).toMatchObject({ type: 'integration', integrationId: 'notes' })
		expect(notes.props.mentionUrl).toBe('/apps/dossiq/api/notes/mention')
		expect(notes.props.noteActions[0]).toMatchObject({
			id: 'push-to-neighbouring-register',
			label: 'Send to the neighbouring register',
			endpoint: '/apps/dossiq/api/cases/{objectId}/notes/push',
		})
	})
})

describe('the redaction page', () => {
	const page = manifest.pages.find((p) => p.id === 'CaseRedaction')

	it('is the library redaction page, with its urls and a trail back through the case', () => {
		expect(page.type).toBe('redaction')
		expect(page.route).toBe('/cases/:id/redact')
		expect(page.config.recordId).toBe('@route.id')
		expect(page.config.config.urls.list).toBe('/apps/dossiq/api/cases/{recordId}/redactions')
		expect(page.config.breadcrumbs.map((crumb) => crumb.label)).toEqual(['All cases', '{identifier}', 'Redact'])
		expect(page.config.breadcrumbs[1].route).toEqual({ name: 'CaseDetail', params: { id: '{recordId}' } })
	})
})
