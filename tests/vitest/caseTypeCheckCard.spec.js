/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypeOverzicht, Controle: the case type page shows the warnings
 * publishing would give, read from the publish check, as one warning notice.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')

describe('the check card of a case type', () => {
	it('reads the publish warnings as a warning notice under the versions', () => {
		const ids = page.config.sideColumn.map((card) => (typeof card === 'string' ? card : card.id))
		expect(ids.indexOf('side-case-type-check')).toBe(ids.indexOf('side-case-type-versions') + 1)
		const card = page.config.sideColumn.find((c) => c.id === 'side-case-type-check')
		expect(card.content.endpointSource).toEqual({ url: '/apps/dossiq/api/case-types/@objectId/publish/validate', responsePath: 'warnings' })
		expect(card.content.presentation).toBe('notice')
		expect(card.content.variant).toBe('warning')
	})
})
