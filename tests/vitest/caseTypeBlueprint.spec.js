/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The badge is the whole point of the blueprint panel.
 *
 * A child case type that declares nothing renders, on screen, exactly like a
 * type that declared four statuses itself. Without the badge an author edits
 * an inherited status expecting it to change only this type, and changes it
 * for the parent and every other child. So the tests that matter here are the
 * ones about which rows carry a badge and which do not.
 *
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/specs/property-definition-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	blueprintSections,
	parentTitleOf,
} from '../../src/utils/caseTypeBlueprint.js'

/**
 * The section with an id.
 *
 * @param {Array<object>} sections The sections.
 * @param {string} id The section id.
 * @return {object} The section.
 */
const section = (sections, id) => sections.find((entry) => entry.id === id)

describe('blueprintSections', () => {
	it('always answers the three sections, in reading order', () => {
		expect(blueprintSections(null).map((s) => s.id)).toEqual([
			'statuses',
			'results',
			'properties',
		])
	})

	it('renders no rows and an empty text for a blueprint it never got', () => {
		for (const entry of blueprintSections(null)) {
			expect(entry.rows).toEqual([])
			expect(entry.emptyText).not.toBe('')
		}
	})

	it('gives an inherited row a badge that names the parent', () => {
		const sections = blueprintSections({
			statusTypes: [
				{
					id: 's1',
					name: 'Ontvangen',
					origin: 'inherited',
					originCaseTypeTitle: 'Bezwaar',
				},
			],
		})

		const row = section(sections, 'statuses').rows[0]
		expect(row.badge).toBe('Inherited')
		expect(row.badgeTitle).toContain('Bezwaar')
	})

	it('badges an inherited row even when the parent has no title', () => {
		const sections = blueprintSections({
			statusTypes: [{ id: 's1', name: 'Ontvangen', origin: 'inherited' }],
		})

		expect(section(sections, 'statuses').rows[0].badge).toBe('Inherited')
	})

	it('gives a shared attribute its own badge, not the inherited one', () => {
		// Shared is not inherited: it came from no ancestor, and a reader
		// looking for what the PARENT contributed must not find it there.
		const sections = blueprintSections({
			propertyDefinitions: [{ id: 'p1', name: 'Kenteken', origin: 'shared' }],
		})

		expect(section(sections, 'properties').rows[0].badge).toBe('Shared')
	})

	it('leaves a row the type declared itself unbadged', () => {
		// Badging every row would make the two cases equally loud, and the one
		// worth noticing is the inherited one.
		const sections = blueprintSections({
			statusTypes: [{ id: 's1', name: 'Ontvangen', origin: 'own' }],
		})

		expect(section(sections, 'statuses').rows[0].badge).toBe('')
	})

	it('treats a row with no origin at all as the type’s own', () => {
		const sections = blueprintSections({
			statusTypes: [{ id: 's1', name: 'Ontvangen' }],
		})

		expect(section(sections, 'statuses').rows[0].badge).toBe('')
	})

	it('uses the labels it is handed, already translated', () => {
		// The labels arrive translated rather than as keys through a callback:
		// a string that lives in the helper and passes through a translate
		// function is invisible to the l10n extractor, so it would never reach
		// a translator and would render in English with every check green.
		const sections = blueprintSections(
			{ statusTypes: [{ id: 's1', name: 'Ontvangen', origin: 'inherited' }] },
			{ statuses: 'Statussen', inherited: 'Geerfd' },
		)

		expect(section(sections, 'statuses').label).toBe('Statussen')
		expect(section(sections, 'statuses').rows[0].badge).toBe('Geerfd')
	})

	it('falls back to a word rather than to undefined', () => {
		const sections = blueprintSections({ statusTypes: [] }, { results: 'X' })

		expect(section(sections, 'statuses').label).toBe('Statuses')
		expect(section(sections, 'statuses').emptyText).not.toContain('undefined')
	})

	it('never translates a row NAME, which is data', () => {
		const sections = blueprintSections(
			{ statusTypes: [{ id: 's1', name: 'Ontvangen' }] },
			{ statuses: 'Statussen' },
		)

		expect(section(sections, 'statuses').rows[0].name).toBe('Ontvangen')
	})

	it('drops a row with no name, which would render as a bare badge', () => {
		const sections = blueprintSections({
			statusTypes: [
				{ id: 's1', origin: 'inherited' },
				{ id: 's2', name: 'Ok' },
			],
		})

		expect(section(sections, 'statuses').rows).toHaveLength(1)
	})

	it('falls back to a title when a row has no name', () => {
		const sections = blueprintSections({
			resultTypes: [{ id: 'r1', title: 'Toegewezen' }],
		})

		expect(section(sections, 'results').rows[0].name).toBe('Toegewezen')
	})

	it('reads an id out of the @self envelope when there is no plain one', () => {
		const sections = blueprintSections({
			statusTypes: [{ '@self': { id: 'deep' }, name: 'Ontvangen' }],
		})

		expect(section(sections, 'statuses').rows[0].key).toBe('deep')
	})

	it('still gives a row with no id a unique key', () => {
		// A duplicate :key silently drops rows from the rendered list.
		const sections = blueprintSections({
			statusTypes: [{ name: 'Een' }, { name: 'Twee' }],
		})

		const keys = section(sections, 'statuses').rows.map((row) => row.key)
		expect(new Set(keys).size).toBe(2)
	})

	it('survives a blueprint whose lists are not lists', () => {
		const sections = blueprintSections({ statusTypes: 'nope', parents: 3 })
		expect(section(sections, 'statuses').rows).toEqual([])
	})
})

describe('parentTitleOf', () => {
	it('names the nearest parent', () => {
		expect(
			parentTitleOf({ parents: [{ title: 'Bezwaar' }, { title: 'Zaak' }] }),
		).toBe('Bezwaar')
	})

	it('is empty for a type that stands on its own', () => {
		expect(parentTitleOf({ parents: [] })).toBe('')
		expect(parentTitleOf(null)).toBe('')
	})
})
