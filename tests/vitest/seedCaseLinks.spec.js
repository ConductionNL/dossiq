/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A seeded record that names its case holds the case's uuid, through an
 * `@ref:case:<slug>` token the import resolves to a seeded case, so a header
 * search hit on it opens that case (unified-search-opens-every-hit-in-dossiq).
 * The demo portal message used to hold the case number "zaak-2026-vth-09128".
 *
 * @spec openspec/changes/unified-search-opens-every-hit-in-dossiq/specs/case-search-via-or-unified-search/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const DIR = path.resolve(__dirname, '../../lib/Settings/register.d')
const objects = fs.readdirSync(DIR)
	.filter((name) => name.endsWith('.json'))
	.flatMap((name) => (JSON.parse(fs.readFileSync(path.join(DIR, name), 'utf8')).components?.objects || []).map((object) => ({ file: name, object })))
const caseSlugs = new Set(objects.filter(({ object }) => object['@self']?.schema === 'case').map(({ object }) => object['@self'].slug))
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

describe('seeded portal messages', () => {
	const messages = objects.filter(({ object }) => object['@self']?.schema === 'portaalBericht')

	it('exist', () => {
		expect(messages.length).toBeGreaterThan(0)
	})

	it.each(messages.map(({ object }) => [object['@self'].slug, object.caseId]))('%s names a seeded case or a case uuid', (slug, caseId) => {
		const ref = /^@ref:case:(.+)$/.exec(caseId)
		expect(Boolean((ref && caseSlugs.has(ref[1])) || UUID.test(caseId)), `${slug}.caseId is ${caseId}`).toBe(true)
	})
})
