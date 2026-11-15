// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Related cases card holds cases, and only cases a handler can name.
 *
 * Round 5 read the card on a case and found its case type, its status type,
 * its workflow template and a bare uuid ("8afb946b-…") listed as related
 * cases. All four came from the library widget's Objects group, which lists
 * every object OpenRegister's `/uses` and `/used` answer. The card now turns
 * that group off, adds the parent case under its own name, and leaves out any
 * row whose far case has no readable title.
 *
 * The suite stubs `@conduction/nextcloud-vue`, so the last test reads the
 * library's own source to pin that `showObjects` is what gates the `/uses` and
 * `/used` reads; a stub honouring a prop the library ignored would prove
 * nothing.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const { relationSections, isBareUuid } =
	await import('../../src/utils/caseRelationHelpers.js')

const here = dirname(fileURLToPath(import.meta.url))
const UUID = '8afb946b-68b4-4618-940e-fecb640fdc41'

describe('Related cases card', () => {
	// What the card draws (no /uses objects as related cases, the parent by
	// its title and never a bare uuid, planned rows that open nothing) is the
	// library related widget's now, with these cases ported to nextcloud-vue
	// CnRelatedObjectsWidgetSources. dossiq's half is the declaration.
	const manifest = JSON.parse(readFileSync(resolve(here, '../../src/manifest.json'), 'utf8'))
	const related = manifest.pages
		.find((page) => page.id === 'CaseDetail')
		.config.widgets.find((widget) => widget.id === 'case-related-panel')
		.content.sections.map((section) => section.widget)
		.find((widget) => widget.id === 'case-related')

	it('does not list the objects a case uses as related cases', () => {
		expect(related.type).toBe('related')
		expect(related.content.showObjects).toBe(false)
	})

	it('shows the parent case by its title, and relations only with a readable title', () => {
		expect(related.content.referenceSections[0]).toMatchObject({ field: 'parentCase', schema: 'case' })
		expect(related.content.extraSources.find((source) => source.key === 'relations').requireLabel).toBe(true)
	})

	it('opens the case a row names, and nothing for a planned row', () => {
		const [planned, relations] = related.content.extraSources
		expect(planned.static).toBe(true)
		expect(relations.rowRoute).toEqual({ name: 'CaseDetail', params: { id: '{caseId}' } })
	})

	it('relies on a rule the library still has: showObjects gates the /uses and /used reads', () => {
		const source = readFileSync(
			resolve(
				here,
				'../../node_modules/@conduction/nextcloud-vue/src/components/CnRelatedObjectsWidget/CnRelatedObjectsWidget.vue',
			),
			'utf8',
		)
		expect(source).toMatch(
			/const objectSuffixes = this\.showObjects\s*\?\s*\['uses', 'used'/,
		)
	})
})

describe('relationSections', () => {
	it('drops a row with no title or a uuid for a title', () => {
		const sections = relationSections([
			{ caseId: 'b', title: 'Besluit', displayLabel: 'Follow-up' },
			{ caseId: UUID, displayLabel: 'Follow-up' },
			{ caseId: 'c', title: UUID, displayLabel: 'Subject' },
		])
		expect(sections).toEqual([
			{
				key: 'relation-Follow-up',
				label: 'Follow-up',
				icon: 'LinkVariant',
				items: [{ id: 'b', label: 'Besluit' }],
			},
		])
	})

	it('tells a uuid from a name', () => {
		expect(isBareUuid(UUID)).toBe(true)
		expect(isBareUuid(` ${UUID.toUpperCase()} `)).toBe(true)
		expect(isBareUuid('Bezwaar 8afb946b')).toBe(false)
		expect(isBareUuid('')).toBe(false)
	})
})
