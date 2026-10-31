/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case-type field as a list column: the answer the case holds under a name.
 *
 * @spec openspec/specs/dossiq-sociaal-domein-participatiewet/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { caseAnswerText } from '../../src/services/caseAnswer.js'

const answers = [
	{ propertyDefinition: 'd-1', name: 'afstandTotArbeidsmarkt', value: 'groot' },
	{ propertyDefinition: 'd-2', name: 'samenwerkendePartijen', value: '["UWV","Werkgever"]' },
	{ propertyDefinition: 'd-3', name: 'instrumenten', value: '[{"kind":"jobcoaching"}]' },
]

describe('caseAnswerText', () => {
	it('reads the answer under its name', () => {
		expect(caseAnswerText(answers, { name: 'afstandTotArbeidsmarkt' })).toBe('groot')
	})

	it('joins a list of words and leaves a list of records alone', () => {
		expect(caseAnswerText(answers, { name: 'samenwerkendePartijen' })).toBe('UWV, Werkgever')
		expect(caseAnswerText(answers, { name: 'instrumenten' })).toBe('')
	})

	it('reads nothing for a missing name, a case without answers or no options', () => {
		expect(caseAnswerText(answers, { name: 'unknown' })).toBe('')
		expect(caseAnswerText(null, { name: 'afstandTotArbeidsmarkt' })).toBe('')
		expect(caseAnswerText(answers)).toBe('')
	})
})

describe('the re-integration view', () => {
	it('shows the distance to the labour market and the benefit case as columns', () => {
		const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const cases = manifest.pages.find((page) => page.id === 'Cases')
		const view = cases.config.defaultSavedViews.find((row) => row.slug === 're-integration-pathways')
		const answered = view.overrides.columns.filter((column) => column.formatter === 'caseAnswer')
		expect(answered.map((column) => column.formatterOptions.name)).toEqual(['bijstandszaak', 'afstandTotArbeidsmarkt'])
	})
})
