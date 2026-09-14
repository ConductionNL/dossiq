// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case lists sort by the declared order and draw the declared colour.
 *
 * 🔴 TWO SILENT FAILURES, AND NEITHER LOOKS LIKE ONE.
 *
 * The first is the sort. A Priority column keyed on `priority` sorts on the
 * server, on a WORD, and the server is right to sort words alphabetically:
 * high, low, normal, urgent. A handler opening that column sees rows move and
 * has no reason to suspect the order is nonsense. So the column is keyed on
 * `priorityOrder`, and the test that carries the requirement is the one that
 * asserts the manifest column's KEY, not that a column called Priority exists.
 *
 * The second is the mirror. `src/utils/priorityValues.js` holds a copy of the
 * order and the colour, because the browser cannot reach the schema when it
 * renders a table cell. A copy that drifts renders a plausible colour and
 * sorts into a plausible order, and the only symptom is a queue whose badges
 * disagree with its sort. So the copy is pinned to
 * `lib/Settings/dossiq_register.json`, which is the declaration, and
 * `CasePriorityDeclarationTest` pins the PHP copy to the same file.
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */
import { describe, expect, it, vi } from 'vitest'
import manifest from '../../src/manifest.json'
import register from '../../lib/Settings/dossiq_register.json'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	t: (app, text) => text,
}))

const {
	PRIORITY_COLOURS,
	PRIORITY_ORDER,
	PRIORITY_VALUES,
	priorityColour,
	priorityLabel,
	priorityOrder,
} = await import('../../src/utils/priorityValues.js')

const declared = register.components.schemas.case.properties.priority
const palette = register.components.schemas.statusType.properties.colour.enum

/**
 * One page's config from the manifest.
 *
 * @param {string} id The page id.
 * @return {object} Its config.
 */
function page(id) {
	return manifest.pages.find((entry) => entry.id === id).config
}

/**
 * The Priority column object on a page, whatever it is keyed on.
 *
 * Found by LABEL rather than by key, deliberately: keying the search on
 * `priorityOrder` would make the test pass by construction and notice nothing
 * if somebody rekeyed the column back to `priority`.
 *
 * @param {string} id The page id.
 * @return {object|undefined} The column, or undefined when there is none.
 */
function priorityColumn(id) {
	return page(id).columns.find(
		(column) => typeof column === 'object' && column.label === 'Priority',
	)
}

describe('the declaration is the source, and the mirror follows it', () => {
	it('the schema declares an order and a colour for every priority', () => {
		expect(Object.keys(declared['x-enum-order'])).toEqual(declared.enum)
		expect(Object.keys(declared['x-enum-colours'])).toEqual(declared.enum)
	})

	it('the browser copy of the order matches the declaration', () => {
		expect(PRIORITY_ORDER).toEqual(declared['x-enum-order'])
	})

	it('the browser copy of the colours matches the declaration', () => {
		expect(PRIORITY_COLOURS).toEqual(declared['x-enum-colours'])
	})

	it('the browser copy of the values matches the declaration', () => {
		expect(PRIORITY_VALUES).toEqual(declared.enum)
	})

	it('every declared colour is a palette token, never a hex value', () => {
		for (const value of declared.enum) {
			const colour = declared['x-enum-colours'][value]
			expect(palette).toContain(colour)
			expect(colour.startsWith('#')).toBe(false)
		}
	})

	it('the order is dense and ascending, so a sort has no ties', () => {
		expect(Object.values(PRIORITY_ORDER)).toEqual([1, 2, 3, 4])
	})
})

describe('the lists are keyed so the server can sort them', () => {
	it.each(['Cases', 'Queue'])(
		'%s has a Priority column keyed on the declared order, not on the word',
		(id) => {
			const column = priorityColumn(id)

			expect(column).toBeDefined()
			expect(column.key).toBe('priorityOrder')
			expect(column.key).not.toBe('priority')
		},
	)

	it.each(['Cases', 'Queue'])('%s renders the column through the badge widget', (id) => {
		expect(priorityColumn(id).widget).toBe('priorityBadge')
	})

	it('sorting on the declared order is not the same as sorting on the word', () => {
		const alphabetical = [...PRIORITY_VALUES].sort()
		const byDeclaredOrder = [...PRIORITY_VALUES].sort(
			(a, b) => priorityOrder(a) - priorityOrder(b),
		)

		// The whole reason the column is keyed on priorityOrder. If these two
		// ever agreed, the extra field would be dead weight and this test
		// should be deleted along with it.
		expect(alphabetical).not.toEqual(byDeclaredOrder)
		expect(byDeclaredOrder).toEqual(['low', 'normal', 'high', 'urgent'])
	})
})

describe('neither list offers a priority to be typed', () => {
	it.each(['Cases', 'Queue'])('%s excludes priority from its form', (id) => {
		expect(page(id).excludeFields).toContain('priority')
		expect(page(id).excludeFields).toContain('priorityOverride')
	})

	it('no create form anywhere in the manifest asks for a priority', () => {
		// Walked rather than listed. Four case create forms carried `priority`
		// in their includeFields, in four different places in this file, and a
		// test that checked the two it remembered would have left the other two.
		const asked = []
		const walk = (node) => {
			if (Array.isArray(node)) {
				node.forEach(walk)
				return
			}
			if (node === null || typeof node !== 'object') return
			if (Array.isArray(node.includeFields) && node.includeFields.includes('priority')) {
				asked.push(node.id ?? node.label ?? 'an unnamed form')
			}
			Object.values(node).forEach(walk)
		}
		walk(manifest)

		expect(asked).toEqual([])
	})

	it('the case page shows the priority without letting anyone edit it', () => {
		const sections = page('CaseDetail').widgets.find(
			(widget) => widget.id === 'case-data-panel',
		).content.sections
		const core = sections.find((section) => section.label === 'Core case data').widget

		expect(core.content.include).toContain('impact')
		expect(core.content.include).toContain('urgency')
		expect(core.content.include).toContain('priority')
		expect(core.content.overrides.priority.editable).toBe(false)
	})

	it('the case page says where the priority came from', () => {
		const sections = page('CaseDetail').widgets.find(
			(widget) => widget.id === 'case-data-panel',
		).content.sections
		const priority = sections.find((section) => section.label === 'Priority')

		expect(priority).toBeDefined()
		// Who, when and why: the three REQ-PRI-03 asks be shown.
		expect(priority.widget.content.include).toContain('priorityOverrideBy')
		expect(priority.widget.content.include).toContain('priorityOverrideAt')
		expect(priority.widget.content.include).toContain('priorityOverrideReason')
		// And an empty override must render empty rather than vanish: a case
		// with no override showing nothing at all reads as a broken panel.
		expect(priority.widget.content.hideEmpty).toBe(false)
	})
})

describe('the badge draws what it is given', () => {
	it('each value resolves to its declared hue', () => {
		for (const value of PRIORITY_VALUES) {
			expect(priorityColour(value)).toBe(declared['x-enum-colours'][value])
		}
	})

	it('a value the schema does not declare falls back to grey, not to nothing', () => {
		// A badge with no colour at all reads as a rendering fault rather than
		// as an unknown value, which is the same reason statusColour.js does
		// this.
		expect(priorityColour('blocker')).toBe('grey')
		expect(priorityColour(undefined)).toBe('grey')
		expect(priorityOrder('blocker')).toBe(0)
	})

	it('each value has a label, so a badge is never blank', () => {
		for (const value of PRIORITY_VALUES) {
			expect(priorityLabel(value)).toBeTruthy()
		}
	})

	it('the labels are four distinct words', () => {
		const labels = PRIORITY_VALUES.map(priorityLabel)
		expect(new Set(labels).size).toBe(4)
	})
})
