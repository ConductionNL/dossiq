/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTenant's header line ends in "een van 6 organisaties op deze omgeving":
 * the organisations on this environment, counted from the same source the
 * organisations list reads.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const pages = read('src/manifest.d/67-environment-organisations.json').pages
const detail = pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const list = pages.find((entry) => entry.id === 'EnvironmentOrganisations').config
const nl = read('l10n/nl.json').translations

describe('organisation header count (DqTenant)', () => {
	it('counts the organisations the list shows', () => {
		expect(detail.headerMetaCounts).toEqual({ organisations: { entitySource: list.entitySource } })
	})

	it('reads een van n organisaties op deze omgeving', () => {
		expect(nl[detail.headerMeta]).toBe('een van {count:organisations} organisaties op deze omgeving')
	})
})
