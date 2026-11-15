/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype's Termijnen card reads "Dwangsom bij niet tijdig beslissen: Ja,
 * na een ingebrekestelling". The case type says so in two generic fields,
 * edited where its other terms are.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-says-whether-a-late-decision-costs-a-penalty
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const fragment = read('lib/Settings/register.d/99-case-type-penalty-on-late-decision.json').components.schemas.caseType
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseTypeDetail')
const full = read('src/manifest.json').pages.find((page) => page.id === 'CaseTypeDetail')
const nl = read('l10n/nl.json').translations

describe('the penalty on a late decision', () => {
	it('is two caseType fields at 1.33.0', () => {
		expect(fragment.version).toBe('1.33.0')
		expect(fragment.properties.penaltyOnLateDecision.type).toBe('boolean')
		expect(fragment.properties.penaltyMaxAmount.type).toBe('number')
	})

	it('reads on the Termijnen card after the decision term, before the suspension (DqZaaktype)', () => {
		const fields = simple.configAppend.widgets.find((widget) => widget.id === 'case-type-terms-written').content.fields
		const penalty = fields.find((field) => field.path === 'penalty')
		expect(nl[penalty.label]).toBe('Dwangsom bij niet tijdig beslissen')
		expect(fields.indexOf(penalty)).toBe(fields.findIndex((field) => field.path === 'decisionTerm') + 1)
		expect(fields.indexOf(penalty)).toBe(fields.findIndex((field) => field.path === 'suspension') - 1)
	})

	it('is edited with the other terms', () => {
		const include = full.config.widgets.find((widget) => widget.id === 'case-type-terms').content.include
		expect(include).toEqual(expect.arrayContaining(['penaltyOnLateDecision', 'penaltyMaxAmount']))
	})
})
