/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A seeded record that names its case holds the case's uuid, through an
 * `@ref:case:<slug>` token the import resolves to a seeded case, so a header
 * search hit on it opens that case (unified-search-opens-every-hit-in-dossiq).
 * The demo portal message and the three demo beschikkingen used to hold case
 * numbers ("zaak-2026-vth-09128", "zaak-2026-wmo-04832", ...).
 *
 * @spec openspec/changes/unified-search-opens-every-hit-in-dossiq/specs/case-search-via-or-unified-search/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

// The register fragments and, since the English demo cases became opt-in
// example data, the demo fragments the example-data step installs.
const DIRS = ['register.d', 'demo.d'].map((dir) => path.resolve(__dirname, '../../lib/Settings', dir))
const objects = DIRS.flatMap((dir) => fs.readdirSync(dir)
	.filter((name) => name.endsWith('.json'))
	.flatMap((name) => (JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8')).components?.objects || []).map((object) => ({ file: name, object }))))
const caseSlugs = new Set(objects.filter(({ object }) => object['@self']?.schema === 'case').map(({ object }) => object['@self'].slug))
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

describe.each(['portaalBericht', 'beschikking'])('seeded %s records', (schema) => {
	const records = objects.filter(({ object }) => object['@self']?.schema === schema)

	it('exist', () => {
		expect(records.length).toBeGreaterThan(0)
	})

	it.each(records.map(({ object }) => [object['@self'].slug, object.caseId]))('%s names a seeded case or a case uuid', (slug, caseId) => {
		const ref = /^@ref:case:(.+)$/.exec(caseId)
		expect(Boolean((ref && caseSlugs.has(ref[1])) || UUID.test(caseId)), `${slug}.caseId is ${caseId}`).toBe(true)
	})
})

describe('the seeded Wmo case the Wmo beschikking belongs to', () => {
	const byslug = (slug) => objects.find(({ object }) => object['@self']?.slug === slug)?.object

	it('is a case on the seeded Wmo-melding case type', () => {
		const wmoCase = byslug('case-wmo-huishoudelijke-hulp')
		expect(wmoCase['@self'].schema).toBe('case')
		expect(wmoCase.caseType).toBe('@ref:caseType:wmo-melding')
		expect(byslug('wmo-melding')['@self'].schema).toBe('caseType')
		expect(byslug('besch-2026-04832').caseId).toBe('@ref:case:case-wmo-huishoudelijke-hulp')
	})
})
