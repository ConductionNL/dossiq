// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Recently opened tile on the Dashboard: when you opened each case, and
 * why it is empty when OpenRegister cannot answer.
 *
 * These two tests lived in caseFavourite.spec.js until one-follow-control
 * (#3529) removed the star and that file with it; they test the tile, not
 * the star, so they moved here.
 *
 * The library parts are imported by source path: `vitest.config.js` aliases
 * the bare package name to a stub, and a stub cannot answer what the
 * installed library renders.
 *
 * @spec openspec/changes/recent-tile-shows-when-you-opened/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import CnDataTable from '@conduction/nextcloud-vue/src/components/CnDataTable/CnDataTable.vue'
import CnWidgetObjectTable from '@conduction/nextcloud-vue/src/components/CnWidgetObjectTable/CnWidgetObjectTable.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const page = (id) => manifest.pages.find((p) => p.id === id)
function recentTile() {
	return page('Dashboard').config.widgets.find((w) => w.id === 'recent-cases')
}

describe('the Recently opened tile', () => {
	/**
	 * The Recently opened tile says WHEN the reader opened each case.
	 *
	 * The moment is `@self.viewedAt` on OpenRegister's metadata envelope, which
	 * the `_recent` lens adds to every object it returns; it is not a case
	 * property, so a column bound to `viewedAt` would render a dash in every row.
	 * The formatter is the library's built-in `daysSince`, resolved the way
	 * CnAppRoot resolves it, so a local formatter under that name would show here.
	 */
	it('shows when you opened each case, as a relative date read off the envelope', async () => {
		const recent = recentTile()
		const keys = recent.content.columns.map((column) => column.key)
		expect(keys).toEqual(['identifier', 'title', '@self.viewedAt'])

		const when = recent.content.columns[2]
		expect(when.formatter).toBe('daysSince')
		expect(when.cellClass).toBe('cn-cell--muted cn-cell--end')
		// The lens carries the order; the tile still declares none of its own.
		expect(recent.content.source.order).toBeUndefined()

		const { BUILT_IN_FORMATTERS } =
			await import('@conduction/nextcloud-vue/src/utils/builtInFormatters.js')
		const { default: formatters } =
			await import('../../src/services/formatters.js')
		const registry = { ...BUILT_IN_FORMATTERS, ...formatters }
		expect(registry.daysSince).toBe(BUILT_IN_FORMATTERS.daysSince)
		// A running phrase (nextcloud-vue 2.77.0, #1408): lower case, so it
		// reads after a title.
		expect(registry.daysSince(new Date().toISOString())).toBe('today')
		const yesterday = new Date()
		yesterday.setDate(yesterday.getDate() - 1)
		expect(registry.daysSince(yesterday.toISOString())).toBe('yesterday')
		const threeDaysAgo = new Date()
		threeDaysAgo.setDate(threeDaysAgo.getDate() - 3)
		expect(registry.daysSince(threeDaysAgo.toISOString())).toBe('3 days ago')
		// No read logged (the audit trail is off): an empty cell, never a guess.
		expect(registry.daysSince(undefined)).toBe('')

		expect(recent.content.emptyText).toBe('Cases you open show up here')
	})

	/**
	 * The Dutch the tile shows comes from the library's own catalogue, so this
	 * reads it there: the singular, the plural and the two named days.
	 */
	it('has the relative date in Dutch in the installed library', () => {
		const nl = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'node_modules',
					'@conduction',
					'nextcloud-vue',
					'l10n',
					'nl.json',
				),
				'utf8',
			),
		)
		expect(nl.translations.today).toBe('vandaag')
		expect(nl.translations.yesterday).toBe('gisteren')
		expect(nl.plurals['{count} day ago']).toEqual([
			'{count} dag geleden',
			'{count} dagen geleden',
		])
	})

	/**
	 * When OpenRegister cannot answer the `_recent` lens it says why
	 * (`@self.lenses.recent.available: false`), and CnDataTable shows the
	 * matching `lensReasonTexts` entry in place of `emptyText`. The library's
	 * own copy says "items"; dossiq says "cases", for all three reasons it
	 * reports. Each value is an English source key with a Dutch translation,
	 * because CnDataTable translates it through the app catalogue.
	 */
	it('says in its own words why the recent tile is empty', () => {
		const texts = recentTile().content.lensReasonTexts
		expect(Object.keys(texts).sort()).toEqual([
			'recent.anonymous',
			'recent.audit-trail-disabled',
			'recent.read-history-unavailable',
		])
		expect(texts['recent.audit-trail-disabled']).toBe(
			'This server does not keep track of which cases you open.',
		)

		const catalogue = (locale) =>
			JSON.parse(
				fs.readFileSync(path.join(ROOT, 'l10n', `${locale}.json`), 'utf8'),
			).translations
		const nl = catalogue('nl')
		expect(nl[texts['recent.audit-trail-disabled']]).toBe(
			'Deze server houdt niet bij welke zaken je opent.',
		)
		expect(nl[texts['recent.anonymous']]).toBe(
			'Log in om te zien welke zaken je onlangs opende.',
		)
		expect(nl[texts['recent.read-history-unavailable']]).toBe(
			'Je recente zaken zijn nu niet beschikbaar.',
		)
	})

	/**
	 * The tile is an `object-table`, which the dashboard renders as
	 * CnWidgetObjectTable with the tile's content as its props; that widget
	 * forwards its remaining props to CnDataTable (nextcloud-vue 2.77.0,
	 * #1421). A library without the prop on either would drop the texts in
	 * silence.
	 */
	it('hands the texts to a table that reads them', () => {
		expect(recentTile().type).toBe('object-table')
		expect(CnWidgetObjectTable.props).toHaveProperty('lensReasonTexts')
		expect(CnDataTable.props).toHaveProperty('lensReasonTexts')
	})
})
