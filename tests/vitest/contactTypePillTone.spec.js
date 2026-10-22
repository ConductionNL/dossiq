/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContact draws "Inwoner" in the teal tone, as DqOrganisatie draws its legal
 * form; the open-case count stays in the work-in-progress tone.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages
const config = (id) => pages.find((page) => page.id === id).config

describe('the relation pages\' pills', () => {
	it('draw the kind of relation in teal on both pages', () => {
		expect(config('ContactDetail').typePill.variant).toBe('teal')
		expect(config('OrganisationDetail').typePill.variant).toBe('teal')
	})

	it('draw the open-case count in purple', () => {
		expect(config('ContactDetail').statusPill.variant).toBe('purple')
	})
})
