/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisaties: each row's building icon sits on a teal tile.
 *
 * @spec openspec/changes/organisations-icon-tile/specs/party-pages/spec.md#requirement-the-organisations-list-draws-its-icon-on-a-teal-tile
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages.find((page) => page.id === 'Organisations').config

describe('DqOrganisaties row icon', () => {
	it('is a building on a teal tile', () => {
		expect(config.rowIcon).toBe('OfficeBuildingOutline')
		expect(config.rowIconTone).toBe('teal')
	})
})
