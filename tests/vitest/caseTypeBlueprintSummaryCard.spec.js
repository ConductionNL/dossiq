// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Statuses, results and attributes" on the case type's Statuses tab is a
 * titled card that leads with what the type offers in numbers, as board
 * DqZaaktypeStatussen draws it; every row stays one click away.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-the-blueprint-reads-as-a-summary-card
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'

const mockGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	return {
		translate: (_app, text, vars) => fill(text, vars),
		translatePlural: (_app, one, many, count, vars) => fill(count === 1 ? one : many, vars).replace('%n', String(count)),
	}
})
vi.mock('@nextcloud/event-bus', () => ({ subscribe: () => {}, unsubscribe: () => {} }))

const { default: CaseTypeBlueprintWidget } = await import('../../src/components/caseType/CaseTypeBlueprintWidget.vue')
const registrySource = fs.readFileSync(path.resolve(__dirname, '../../src/registry.js'), 'utf8')

describe('the case type blueprint as a summary card', () => {
	it('is a titled card with the board\'s line under its title', () => {
		const block = registrySource.slice(registrySource.indexOf('CaseTypeBlueprintWidget: {'))
		expect(block.slice(0, block.indexOf('},'))).toContain("chrome: 'card'")
		const def = manifest.pages.find((p) => p.id === 'CaseTypeDetail').config.widgets.find((w) => w.id === 'case-type-blueprint')
		expect(nl.translations[def.subtitle]).toBe('Wat dit zaaktype aanbiedt, ook wat het van het moedertype erft')
	})

	it('leads with statuses and their final ones, results, and attributes with what they inherit', async () => {
		mockGet.mockResolvedValue({ data: {
			statusTypes: [{ name: 'Ontvangen' }, { name: 'Verleend', isFinal: true }, { name: 'Geweigerd', isFinal: true }],
			resultTypes: [{ name: 'Verleend' }, { name: 'Geweigerd' }, { name: 'Ingetrokken' }],
			propertyDefinitions: [],
			counts: { propertyDefinitions: { total: 14, inherited: 6 } },
			parents: [{ title: 'Omgevingsvergunning' }],
		} })
		const w = mount(CaseTypeBlueprintWidget, { global: { mocks: { $route: { params: { id: 'ct-1' } } } } })
		await flushPromises()
		const facts = w.findAll('[data-testid="case-type-blueprint-summary"] dd').map((d) => d.text())
		expect(facts).toEqual(['3, of which 2 final', '3', '14 fields, 6 inherited from Omgevingsvergunning'])
		expect(w.find('details [data-testid="case-type-statuses"]').exists()).toBe(true)
	})
})
