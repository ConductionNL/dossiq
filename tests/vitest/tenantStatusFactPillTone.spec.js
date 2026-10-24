/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tenant's status card draws its status pill in the same tone as the
 * header pill, as DqTenant does ("Actief" green in both places).
 *
 * @spec exclude board tone of the tenant status card pill (DqTenant), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.d/67-environment-organisations.json'), 'utf8'))
const page = fragment.pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail')

describe('the tenant status card pill', () => {
	it('takes the header pill\'s tones', () => {
		const card = page.config.sideColumn.find((widget) => widget.id === 'organisation-side-status')
		const pill = card.content.fields.find((field) => field.as === 'pill' && field.path === 'status')
		expect(pill.variantMap).toEqual(page.config.statusPill.colorMap)
	})
})
