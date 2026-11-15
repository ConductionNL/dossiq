/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisatie draws "Lopende zaken" and "Open tickets" as short
 * stacked lists: the row 10px under the title, no rule above it.
 *
 * @spec openspec/changes/organisation-side-lists-stacked/specs/party-pages/spec.md#requirement-the-organisations-side-lists-are-short-stacked-lists
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const side = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'OrganisationDetail').config.sideColumn

describe('organisation side lists (DqOrganisatie)', () => {
	it.each(['side-open-case', 'side-open-ticket'])('%s is a stacked list', (id) => {
		expect(side.find((widget) => widget.id === id).content.density).toBe('stacked')
	})
})
