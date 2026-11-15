/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * First today is the library banner, declared in the simple profile: an
 * attention card naming the reader's own open case furthest past its
 * deadline (the first row of an ordered query is its subject), silent when
 * there is none. Before nextcloud-vue 2.81.0 the banner was only registered
 * under the widget type `banner`, so this also pins that the installed
 * library still answers that type and exports the component.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const LIB = ['node_modules', '@conduction', 'nextcloud-vue', 'src']

/**
 * Find a widget in the simple profile by id, wherever it is declared.
 *
 * @param {string} id The widget id.
 * @return {object|undefined} The definition.
 */
function simpleWidget(id) {
	let found
	const walk = (node) => {
		if (found || node === null || typeof node !== 'object') {
			return
		}
		if (!Array.isArray(node) && node.id === id && node.type) {
			found = node
			return
		}
		Object.values(node).forEach(walk)
	}
	walk(JSON.parse(read('src', 'menu-layout.simple.json')))
	return found
}

describe('First today and the library banner', () => {
	const card = simpleWidget('simple-first-today')

	it('is the attention card, naming the first row of the past-term query', () => {
		expect(card.type).toBe('banner')
		expect(card.content.layout).toBe('attention')
		expect(card.content.kicker).toBe('First today')
		expect(card.content.title).toBe('{subject.title}')
		expect(card.content.visibleWhen).toMatchObject({ op: 'gt', value: 0 })
		expect(card.content.visibleWhen.source.filter.assignee).toBe('@me')
	})

	it('offers the board\'s ways on: all cases past their term, the board, and the case itself last', () => {
		// DqMijnWerk "Vandaag eerst": Alle zaken over termijn, Naar het
		// werkbord, Termijn opschorten, Zaak openen (primary).
		expect(card.content.actions.map((action) => action.label)).toEqual(['All cases past their deadline', 'Go to the board', 'Suspend the term', 'Open case'])
		// Suspending opens the case's lifecycle menu on that act, for the case the card names.
		expect(card.content.actions[2]).toEqual({
			label: 'Suspend the term',
			type: 'open-modal',
			target: 'CaseLifecycleMenuDialog',
			props: { caseId: '@subject.id', act: 'suspend' },
		})
		expect(read('src', 'registry.js')).toContain('CaseLifecycleMenuDialog: {')
		expect(card.content.actions[1]).toEqual({ label: 'Go to the board', route: { name: 'Cases', query: { _view: 'board' } } })
		expect(card.content.actions.filter((action) => action.primary).map((action) => action.label)).toEqual(['Open case'])
	})

	it('relies on what the installed library actually exports', () => {
		const index = read(...LIB, 'index.js')
		expect(index).toMatch(/\bCnBannerWidget\b/)
	})
})
