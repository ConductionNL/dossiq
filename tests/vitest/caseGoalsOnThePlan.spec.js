/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaak Zaakplan ends with the case's goals: "Doelen nog geen doelen: wat
 * wil de verzoeker dat er verandert?" and "Doel toevoegen", the prompt from the
 * case type, the row only for a case type that sets one.
 *
 * @spec openspec/changes/case-goals/specs/case-goals/spec.md#requirement-the-case-type-words-the-empty-goals-row
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const widgets = simple.pages.find((entry) => entry.id === 'CaseDetail').configAppend.widgets
const plan = widgets.find((entry) => entry.id === 'case-plan-card')
const goals = widgets.find((entry) => entry.id === 'case-goals')
const woo = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/register.d/81-woo-verzoek-version-2.json'), 'utf8'))
	.components.objects.find((entry) => entry['@self'] && entry['@self'].slug === 'woo-verzoek-v2')

describe('the case goals on the plan', () => {
	it('ends the Zaakplan card', () => {
		expect(plan.content.trailWidgetIds).toEqual(['case-goals'])
	})

	it('reads the goals and the case type prompt from one answer', () => {
		expect(goals.type).toBe('record-list')
		expect(goals.content.endpointSource).toEqual({ url: '/apps/dossiq/api/cases/@objectId/goals', responsePath: 'results' })
		expect(goals.content.footerAction.note).toEqual({ path: 'prompt', lead: 'Goals' })
		expect(goals.content.footerAction.layout).toBe('row')
		expect(goals.content.footerAction.props.config.confirm.request).toEqual({ method: 'POST', url: '/apps/dossiq/api/cases/@objectId/goals' })
	})

	it('shows only for a case type with a prompt', () => {
		expect(goals.visibleWhen).toEqual({ field: '@context.caseType.goalPrompt', op: 'notEmpty' })
	})

	it('reads as the board in Dutch', () => {
		expect(nl[goals.content.footerAction.note.lead]).toBe('Doelen')
		expect(nl[goals.content.footerAction.label]).toBe('Doel toevoegen')
		expect(nl[woo.goalPrompt]).toBe('nog geen doelen: wat wil de verzoeker dat er verandert?')
	})
})
