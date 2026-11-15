/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZoekresultaten and DqPostvak end their list in "12 van 12 · 20 per
 * pagina". The board pager already leads with the shown/total count, so the
 * page's footer note carries only the page size; a note with its own
 * {shown}/{total} printed the tokens unfilled after the count (r44).
 *
 * @spec openspec/specs/semantic-case-intake/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const page = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.d', file), 'utf8')).pages[0]

describe('search and postvak footer note', () => {
	it.each(['89-search.json', '88-postvak.json'])('%s carries only the page size', (file) => {
		expect(page(file).config.footerNote).toBe('20 per page')
	})
})
