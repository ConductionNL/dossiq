/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A person's contact page draws its "Resident" type pill in the teal tone,
 * as DqContact does; blue is the case and status colour.
 *
 * @spec exclude board tone of one header pill (DqContact), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))

describe('the contact page type pill', () => {
	it('is the teal "Resident" pill', () => {
		const page = manifest.pages.find((entry) => entry.id === 'ContactDetail')
		expect(page.config.typePill).toEqual({ text: 'Resident', variant: 'teal' })
	})
})
