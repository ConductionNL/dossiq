/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One history of the case, as the manifest declares it (case-timeline, A05).
 *
 * The case page carried its history TWICE: the `audit` tab over
 * OpenRegister's audit trail, and a `version-history` tab rendering the same
 * rows as a field diff. This spec is the half that fails when the second one
 * comes back, and the half that fails if retiring it takes the other 13
 * pages' tabs with it. Neither failure shows on screen: a sidebar with one
 * tab fewer and a sidebar that lost the wrong tab look the same until you
 * open the page that needed it.
 *
 * @spec openspec/changes/case-timeline/specs/case-dashboard-view/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

/** The CaseDetail page as the manifest declares it. @return {object} The page. */
function caseDetail() {
	return manifest.pages.find((page) => page.id === 'CaseDetail')
}

/**
 * The sidebar tabs of one page.
 *
 * @param {string} id The manifest page id.
 * @return {Array<object>} The tab entries, or an empty list.
 */
function sidebarTabs(id) {
	const page = manifest.pages.find((entry) => entry.id === id)
	return page?.config?.sidebar?.tabs ?? []
}

/**
 * The sidebar tab counts of every detail page but CaseDetail, as they stood
 * on `development` before this change.
 *
 * Written out rather than derived: a derived expectation reads the same file
 * the assertion reads, so it would agree with any value the manifest happens
 * to hold, including the one this change was supposed not to produce.
 */
const OTHER_SIDEBARS = {
	BezwaarDetail: 2,
	BezwaarDecisionDetail: 2,
	TaskDetail: 2,
	AdviceDetail: 2,
	WmsLayerDetail: 2,
	TenantDetail: 2,
	TransferDetail: 2,
	WorkflowDefinitionDetail: 2,
	StatusRecordDetail: 2,
	LhsRecommendationDetail: 2,
	BezwaarCommitteeDetail: 2,
	BezwaarAdviceRequestDetail: 2,
	BeroepDetail: 2,
}

describe('CaseDetail — one history tab (task 1.1)', () => {
	it('carries exactly one tab whose widget type is audit', () => {
		const auditTabs = sidebarTabs('CaseDetail').filter((tab) =>
			(tab.widgets ?? []).some((entry) => entry.type === 'audit'),
		)
		expect(auditTabs).toHaveLength(1)
		expect(auditTabs[0].id).toBe('audit')
		expect(auditTabs[0].label).toBe('History')
	})

	it('has no version-history tab left on the case page', () => {
		const tabs = sidebarTabs('CaseDetail')
		expect(tabs.map((tab) => tab.id)).not.toContain('version-history')
		expect(tabs.map((tab) => tab.component)).not.toContain(
			'VersionHistoryLeafTab',
		)
	})

	it('leaves every other detail page its version history', () => {
		for (const [id, count] of Object.entries(OTHER_SIDEBARS)) {
			const tabs = sidebarTabs(id)
			expect(tabs, `${id} sidebar tab count`).toHaveLength(count)
			expect(
				tabs.map((tab) => tab.component),
				`${id} lost its version history`,
			).toContain('VersionHistoryLeafTab')
		}
	})

	it('keeps the VersionHistoryLeafTab registry entry the 13 pages resolve', () => {
		// A tab naming a component the registry does not hold renders an empty
		// panel and a console warning nobody reads. Deleting the entry along
		// with the CaseDetail tab would take all 13 sidebars down silently.
		expect(registrySource).toContain('VersionHistoryLeafTab:')
	})
})

describe('CaseDetail — the timeline is not also a body tab (task 4.1)', () => {
	it('declares no case-timeline widget and no Timeline tab', () => {
		// The whole of row A05 is that the case keeps ONE history. A body
		// panel over the same audit log beside a sidebar tab with filters is
		// two views of one log again.
		const config = caseDetail().config
		expect(config.widgets.map((widget) => widget.id)).not.toContain(
			'case-timeline',
		)
		const panels = config.widgets.find((widget) => widget.id === 'case-panels')
		const tabs = panels.content.tabs
		expect(tabs.map((tab) => tab.widgetId)).not.toContain('case-timeline')
		expect(tabs.map((tab) => tab.label)).not.toContain('Timeline')
	})
})
