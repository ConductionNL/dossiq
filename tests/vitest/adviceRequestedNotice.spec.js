/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaak: the "Advies gevraagd" notice at the top of the case's side column
 * shows while an open advice request points at this case, and names the
 * first one, the soonest due.
 *
 * @spec openspec/specs/advice-requested-notice/spec.md#requirement-a-case-shows-that-advice-is-still-asked
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const layout = JSON.parse(fs.readFileSync(path.join(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(__dirname, '../../l10n/nl.json'), 'utf8')).translations

/**
 * The page overlay whose side column holds the case tiles.
 *
 * @return {object} The overlay's config.
 */
function caseSideColumn() {
	const pages = Object.values(layout.pages || {})
	const page = pages.find((p) => p && p.config && Array.isArray(p.config.sideColumn) && p.config.sideColumn.includes('case-tile-deadline'))
	return page.config.sideColumn
}

describe('the advice requested notice', () => {
	it('opens the case side column', () => {
		const column = caseSideColumn()
		expect(column[0].id).toBe('side-advice-requested')
		expect(column[0].type).toBe('banner')
		expect(column[1]).toBe('case-tile-deadline')
	})

	it('shows while an open advice request points at this case, naming the soonest', () => {
		const { visibleWhen, lead, text } = caseSideColumn()[0].content
		expect(visibleWhen.source).toEqual({
			register: 'dossiq',
			schema: 'adviesAanvraag',
			filter: { case: '@objectId', status: 'requested' },
			order: { deadline: 'asc' },
		})
		expect([visibleWhen.field, visibleWhen.op, visibleWhen.value]).toEqual(['@total', 'gt', 0])
		expect(text).toContain('{subject.advisor}')
		expect(text).toContain('{subject.deadline|longdate}')
		expect(nl[lead]).toBe('Advies gevraagd')
		expect(nl[text]).toContain('Afronden kan pas daarna')
	})
})
