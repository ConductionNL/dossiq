/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's identity header, as the manifest declares it.
 *
 * Every assertion here guards a declaration that renders nothing when it is
 * wrong and says nothing about it: a `subtitleField` no host reads, a layout
 * cell pointing at a widget id that no longer exists, a tab whose widget was
 * never declared. The manifest validator checks SHAPES; it cannot check that
 * the shapes refer to each other.
 *
 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

/** The CaseDetail page as the manifest declares it. @return {object} The page. */
const caseDetail = () => manifest.pages.find((page) => page.id === 'CaseDetail')

describe('CaseDetail — the case number under the title (task 1.1)', () => {
	it('names identifier as the subtitle field', () => {
		expect(caseDetail().config.subtitleField).toBe('identifier')
	})

	it('leaves the menu and the page count alone', () => {
		// The subtitle is one key on one page. A change that moves the page
		// count or the menu has done something else as well.
		expect(manifest.pages).toHaveLength(43)
		expect(manifest.menu.filter((entry) => entry.route === 'Cases')).toHaveLength(
			1,
		)
	})
})
