/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTaken and DqOrganisaties draw no "Weergave opslaan" in the toolbar; the
 * saved views stay reachable in the side panel.
 *
 * @spec exclude board chrome of two lists (DqTaken, DqOrganisaties), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))

describe.each(['Tasks', 'Organisations'])('the %s list', (id) => {
	const config = manifest.pages.find((entry) => entry.id === id).config

	it('keeps its saved views but leaves Save view out of the toolbar', () => {
		expect(config.allowSavedViews).toBe(true)
		expect(config.savedViewSave).toBe(false)
	})
})
