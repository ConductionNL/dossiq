// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The flag on the case page, and the chip that finds the flagged ones.
 *
 * 🔴 THE BROWSER NEVER WRITES THE HISTORY ITSELF. `needsAttention` and
 * `attentionFlagHistory` are fields on the case and OpenRegister would take a
 * patch on either straight from here. What a patch cannot do is refuse a
 * clearing with no reason, and it cannot be trusted to send back a history
 * with every earlier row still in it: one stale copy in a component and three
 * raisings are gone with nothing failing anywhere. So the assertion that
 * matters here is the URL: both acts go to dossiq's own endpoint, and no
 * object write is made at all.
 *
 * 🔴 THE CHIP FILTERS ON THE STORED BOOLEAN, NOT ON `_unread`. Those are two
 * different facts, and a chip pointed at the wrong one would narrow the list
 * plausibly and answer a different question. The manifest is read here rather
 * than described, because a chip that quietly changed its filter is exactly
 * the kind of thing that reads fine in a diff.
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
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')
const cases = manifest.pages.find((p) => p.id === 'Cases')


describe('the strip is declared on the case page', () => {
	const attention = caseDetail.config.widgets.find((w) => w.id === 'case-attention')

	it('rides the banner row above the panels', () => {
		const stack = caseDetail.config.widgets.find((w) => w.id === 'case-banner-stack')
		expect(stack.content.sections.map((section) => section.widgetId)).toContain('case-attention')
		const row = caseDetail.config.layout.find((cell) => cell.widgetId === 'case-banner-stack')
		const panels = caseDetail.config.layout.find((cell) => cell.widgetId === 'case-panels')
		expect(row.gridY).toBeLessThan(panels.gridY)
	})

	it('is the library attention flag over dossiq\'s endpoint, not an object write', () => {
		// Raising needs a reason both ways and the refusal is the server's
		// (CaseAttentionFlagService): what the strip does with them is
		// nextcloud-vue CnAttentionFlagWidget's, with these cases ported there.
		expect(attention.type).toBe('attention-flag')
		expect(attention.content).toMatchObject({ endpoint: '/apps/dossiq/api/case/@objectId/attention', raisePath: '/raise', clearPath: '/clear' })
		expect(attention.content.labels.reasonRaise).toBe('Why this case needs attention')
		expect(registrySource).not.toContain("'case-attention': {")
	})

	it('reads the assessment off the case, so a reader without the permission sees none', () => {
		expect(attention.content.assessment).toEqual({ path: 'riskAssessment', reviewDateField: 'reviewDate' })
	})
})

describe('the work list finds the flagged ones', () => {
	it('filters on the stored flag and not on the read state', () => {
		const chip = cases.config.quickFilters.find(
			(q) => q.label === 'Needs attention',
		)

		expect(chip, 'the Needs attention chip is missing').toBeTruthy()
		expect(chip.filter.needsAttention).toBe(true)
		expect(
			chip.filter._unread,
			'the flag is not the per-user read state',
		).toBeUndefined()
	})

	it('narrows the risk chip to the levels that are worth finding', () => {
		const chip = cases.config.quickFilters.find(
			(q) => q.label === 'Assessed high risk',
		)

		expect(chip).toBeTruthy()
		expect(chip.filter.riskLevel).toEqual(['high', 'critical'])
	})

	it('declares no risk column, because a column cannot be absent per reader', () => {
		// A header with nothing under it tells a reader without the permission
		// that an assessment exists, which is most of what the permission was
		// for. The chip narrows to nothing for them instead, which says no more
		// than that they have nothing to see.
		const keys = cases.config.columns.map((c) => c.key)
		expect(keys).not.toContain('riskLevel')
		expect(keys).not.toContain('riskAssessment')
	})
})
