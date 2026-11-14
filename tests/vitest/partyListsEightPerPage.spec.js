/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContacten and DqOrganisaties show eight rows per page ("8 van 187",
 * "8 van 23"); live showed twenty, so the pager stood rows lower.
 *
 * @spec openspec/specs/party-pages/spec.md#requirement-the-party-lists-show-eight-rows-per-page
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages

describe.each(['Contacts', 'Organisations'])('%s list', (pageId) => {
	it('pages by eight', () => {
		expect(pages.find((page) => page.id === pageId).config.pagination).toEqual({ limit: 8 })
	})
})
