/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSociaalDomein Gezinsplan: a goal's interventions are plain rows.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

describe('the family plan card', () => {
	it('draws the interventions as rows', () => {
		const text = JSON.stringify(manifest)
		expect(text).toContain('"itemsPath":"interventions","itemLook":"rows"')
	})
})
