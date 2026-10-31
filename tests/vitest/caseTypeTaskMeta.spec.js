// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktype, Fasen en taken: a task card with a term reads its role and
 * the term ("Intaker · 5 werkdagen"), one without a term reads its description
 * ("Automatisch, bericht aan de aanvrager"). The parts are filled as the
 * grouped cards fill them: a part whose `when` does not hold, or whose token
 * is empty, is left out with its separator.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
const board = page.configAppend.widgets.find((w) => w.id === 'case-type-phase-board')

/**
 * The meta line of one task card, the parts filled as plain tokens.
 *
 * @param {object} task The task as the phases endpoint answers it.
 * @return {string} The line.
 */
function metaOf(task) {
	return board.content.itemMeta
		.filter((part) => typeof part !== 'object' || !part.when || evaluateVisibleWhenLocal(part.when, task))
		.map((part) => {
			const template = typeof part === 'object' ? part.text : part
			let missing = false
			const text = template.replace(/\{(\w+)\}/g, (_m, key) => {
				const value = Array.isArray(task[key]) ? task[key].join(', ') : task[key]
				if (value === undefined || value === null || value === '') {
					missing = true
				}
				return value ?? ''
			})
			return missing ? '' : text
		})
		.filter((text) => text !== '')
		.join(' · ')
}

describe('the task cards on the Phases and tasks tab', () => {
	it('reads the role and the term of a task with a term', () => {
		const task = { roleName: 'Intaker', candidateGroups: ['zuiddrecht-vergunningen'], leadTimeDays: 5, description: 'Nagaan of de aanvraag compleet is.' }
		expect(metaOf(task)).toBe('Intaker · 5 working days')
	})

	it('reads the description of a task without a term, not its group or "0 working days"', () => {
		const task = { roleName: '', candidateGroups: [], leadTimeDays: 0, description: 'Automatisch, bericht aan de aanvrager' }
		expect(metaOf(task)).toBe('Automatisch, bericht aan de aanvrager')
		const routed = { roleName: 'Vergunningverlener', candidateGroups: ['zuiddrecht-vergunningen'], leadTimeDays: 0, description: 'Alleen in de variant Met welstand' }
		expect(metaOf(routed)).toBe('Alleen in de variant Met welstand')
	})

	it('falls back to the group when a task routes to no role, and to the role when it has no description', () => {
		expect(metaOf({ roleName: '', candidateGroups: ['zuiddrecht-vergunningen'], leadTimeDays: 3, description: '' })).toBe('zuiddrecht-vergunningen · 3 working days')
		expect(metaOf({ roleName: 'Intaker', candidateGroups: [], leadTimeDays: 0, description: '' })).toBe('Intaker')
	})
})
