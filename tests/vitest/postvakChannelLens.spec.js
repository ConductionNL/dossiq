// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * The postvak narrows by channel on the server, and its default lens is every
 * channel, named on the Active line (board DqPostvak, "Kanaal: alle").
 *
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.d/88-postvak.json'), 'utf8'))
const page = fragment.pages.find((p) => p.id === 'Postvak')
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

describe('Postvak channel filter', () => {
	it('hands a channel filter to the triage endpoint as its channel parameter', () => {
		expect(page.config.sourceConfig.searchFields.channel).toEqual({ param: 'channel', single: true })
	})

	it('offers the channels the endpoint counts as a picker after the search', () => {
		const [facet] = page.config.sourceConfig.facets
		expect(facet).toMatchObject({ field: 'channel', path: 'channels', label: 'Channel', allLabel: 'all' })
		expect(page.config.sourceConfig.searchFields[facet.field]).toBeTruthy()
		expect(nl[facet.label]).toBe('Kanaal')
		expect(nl[facet.allLabel]).toBe('alle')
		for (const label of Object.values(facet.labels)) {
			expect(nl[label]).toBeTruthy()
		}
	})

	it('opens on every channel, which the Active line names', () => {
		const lens = page.config.quickFilters.find((tab) => tab.default === true)
		expect(lens.filter).toEqual({})
		expect(lens.activeLabel).toBe('Channel: all')
		expect(nl[lens.activeLabel]).toBe('Kanaal: alle')
	})
})
