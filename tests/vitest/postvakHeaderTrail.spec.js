// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * The postvak's header line reads as board DqPostvak draws it: the page as
 * its one crumb, then the note, "Postvak · berichten van de hele organisatie
 * die nog bij geen zaak horen" (nextcloud-vue header-trail-current-once).
 *
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.d/88-postvak.json'), 'utf8'))
const page = fragment.pages.find((p) => p.id === 'Postvak')

describe('Postvak header line', () => {
	it('names the page as its crumb before the note', () => {
		expect(page.config.breadcrumb).toEqual({ label: page.title, route: 'Postvak' })
		expect(page.config.headerMeta).toBe('messages for the whole organisation that belong to no case yet')
	})
})
