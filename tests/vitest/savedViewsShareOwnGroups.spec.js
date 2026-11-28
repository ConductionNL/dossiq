/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A saved view is shared with the reader's own teams: every list page that
 * lets a reader save a view limits the share picker to the groups the reader
 * is in (nextcloud-vue `savedViewsShareGroups: 'mine'`), so a team lead cannot
 * share a view with a team they are not part of.
 *
 * @spec openspec/changes/archive/2026-11-26-case-views-shared-with-a-team/specs/case-search-and-lists/spec.md#scenario-share-targets-are-the-readers-own-teams
 */

import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC = resolve(__dirname, '../../src')

/**
 * Every page the manifest and its fragments declare.
 *
 * @return {Array<object>} The pages.
 */
function pages() {
	const files = ['manifest.json', ...readdirSync(resolve(SRC, 'manifest.d')).filter((f) => f.endsWith('.json')).map((f) => `manifest.d/${f}`)]
	return files.flatMap((file) => {
		const doc = JSON.parse(readFileSync(resolve(SRC, file), 'utf8'))
		return Array.isArray(doc?.pages) ? doc.pages : []
	})
}

describe('saved views are shared with the reader\'s own teams', () => {
	const withViews = pages().filter((page) => page.config?.allowSavedViews === true)

	it('finds the list pages that let a reader save a view', () => {
		expect(withViews.map((page) => page.id)).toEqual(expect.arrayContaining(['Cases', 'Tasks']))
	})

	it('limits the share picker to the groups the reader is in on every one of them', () => {
		const loose = withViews.filter((page) => page.config.savedViewsShareGroups !== 'mine').map((page) => page.id)

		expect(loose).toEqual([])
	})
})
