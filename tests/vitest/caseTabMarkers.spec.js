// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A marker and an unread badge are different facts, and the page has to show
 * that.
 *
 * 🔴 THIS IS THE FILE THAT STOPS THE TWO COLLAPSING INTO ONE BADGE. Both point
 * at a panel of the case page, so the cheap thing to build is one "this panel
 * needs you" count over the pair. It would be wrong in a way nobody would
 * notice for months: the unread badge is per reader and goes because somebody
 * looked, the marker is about the world and goes when the work is done. A
 * handler who cannot tell them apart cannot tell a colleague's unopened note
 * from a document that failed its virus scan.
 *
 * So: the marker rows come from the CASE and are drawn by the attention strip
 * (nextcloud-vue CnAttentionFlagWidget, which offers no gesture that dismisses
 * one), the unread counts come from the read state and are drawn on the tabs,
 * and the marker survives everything that clears the badge.
 *
 * 🔴 A MARKER NAMES A PANEL THE PAGE ACTUALLY RENDERS. A marker pointing at a
 * panel that is not in the tab strip sends a handler looking for a tab that
 * does not exist. The manifest is read here rather than described.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')
const panelIds = caseDetail.config.widgets
	.find((w) => w.id === 'case-panels')
	.content.tabs.map((t) => t.widgetId)



describe('a marker names the panel where the work is', () => {
	const attention = caseDetail.config.widgets.find((w) => w.id === 'case-attention')

	it('reads the markers off the case, under a label per panel', () => {
		expect(attention.type).toBe('attention-flag')
		expect(attention.content.markersPath).toBe('attentionMarkers')
		expect(attention.content.panelLabels['case-work-panel']).toBe('Work')
	})

	it('labels every panel the schema lets a marker name', () => {
		const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'register.d', '38-markers-and-assessments.json'), 'utf8'))
		const declared = fragment.components.schemas.caseType.properties.attentionMarkers.items.properties.tab.enum
		expect(declared.filter((id) => !attention.content.panelLabels[id])).toEqual([])
	})
})

describe('the marker is not the unread badge', () => {
	it('is drawn in the attention strip, while the unread counts are on the tabs', () => {
		// A marker survives opening the panel it names, an unread count does
		// not, so they must not merge into one strip.
		const stack = caseDetail.config.widgets.find((w) => w.id === 'case-banner-stack')
		const panels = caseDetail.config.widgets.find((w) => w.id === 'case-panels')
		expect(stack.content.sections.map((section) => section.widgetId)).toContain('case-attention')
		expect(panels.content.badgeSource).toBe('readState')
		expect(caseDetail.config.widgets.some((w) => w.type === 'case-unread')).toBe(false)
	})
})

describe('the declared panels are panels the page renders', () => {
	it('offers no panel in the schema the tab strip does not carry', () => {
		const fragment = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'lib',
					'Settings',
					'register.d',
					'38-markers-and-assessments.json',
				),
				'utf8',
			),
		)
		const declared =
			fragment.components.schemas.caseType.properties.attentionMarkers.items
				.properties.tab.enum

		expect(declared.filter((id) => panelIds.includes(id) === false)).toEqual([])
	})

	it('offers exactly one clearing, and it is not a dismissal', () => {
		const fragment = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'lib',
					'Settings',
					'register.d',
					'38-markers-and-assessments.json',
				),
				'utf8',
			),
		)
		const clearing =
			fragment.components.schemas.caseType.properties.attentionMarkers.items
				.properties.clearWhen

		expect(clearing.enum).toEqual(['condition-no-longer-true'])
	})
})
