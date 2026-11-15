/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype's header row ends in "laatst gewijzigd 2 okt door Lars de
 * Koning": the newest change in the case type's audit trail.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const nl = read('l10n/nl.json').translations

describe('case type header meta (DqZaaktype)', () => {
	it('reads the last change from the audit trail', () => {
		expect(page.config.headerMetaLastChange).toBe(true)
		expect(page.config.headerMeta).toBe('last changed {_lastChange.created|date} by {_lastChange.userName}')
	})

	it('reads laatst gewijzigd ... door ... in Dutch', () => {
		expect(nl[page.config.headerMeta]).toBe('laatst gewijzigd {_lastChange.created|date} door {_lastChange.userName}')
	})
})
