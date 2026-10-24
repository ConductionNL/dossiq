/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContracten: values in whole euros ("€ 2.400.000") and an expiring
 * contract as a grey pill. "neutral" is no badge variant, so the pill drew no
 * background at all.
 *
 * @spec openspec/specs/dossiq-procurement-contract-lifecycle/spec.md
 */

import { BADGE_VARIANTS } from '@conduction/nextcloud-vue/src/utils/badgeVariants.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/70-privacy-and-procurement.json'), 'utf8'))
const contracts = fragment.pages.find((page) => page.id === 'Contracts')
const column = (key) => contracts.config.columns.find((col) => col.key === key)

describe('the contracts table (DqContracten)', () => {
	it('shows values in whole euros', () => {
		expect(column('value').formatter).toBe('currency')
		expect(column('value').formatterOptions).toEqual({ decimals: 0 })
	})

	it('draws every status in a badge variant the library knows', () => {
		const tones = Object.values(column('status').widgetProps.colorMap)
		expect(tones.every((tone) => BADGE_VARIANTS.includes(tone))).toBe(true)
		expect(column('status').widgetProps.colorMap.ending).toBe('default')
	})
})
