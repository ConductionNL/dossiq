/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype Varianten: "Variant toevoegen" sits in the card's title row,
 * and the name and description fields open above the variants.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
const page = layout.pages.find((entry) => entry.id === 'CaseTypeDetail')
const variants = page.configAppend.widgets.find((widget) => widget.id === 'case-type-variants').content

describe('the Variants card (DqZaaktype)', () => {
	it('puts Add a variant in the card title row', () => {
		expect(variants.add).toMatchObject({ label: 'Add a variant', trigger: 'button', placement: 'title' })
	})
})
