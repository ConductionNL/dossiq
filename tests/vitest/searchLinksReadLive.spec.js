/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * unified-search-opens-every-hit-in-dossiq, task 2.1: a read of the live
 * objects (22 October) showed caseCustody.caseId, fieldInspection.caseRef and
 * toestemming.caseId holding a bare case uuid, so a search hit on one of them
 * opens its case.
 *
 * @spec openspec/changes/unified-search-opens-every-hit-in-dossiq/specs/case-search-via-or-unified-search/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const deepLinks = readJson('src', 'manifest.json').deepLinks
const nl = readJson('l10n', 'nl.json').translations

describe('records read live open their case from search', () => {
	it.each([
		['caseCustody', '/apps/dossiq/cases/{caseId}'],
		['fieldInspection', '/apps/dossiq/cases/{caseRef}'],
		['toestemming', '/apps/dossiq/cases/{caseId}'],
	])('%s opens %s', (slug, template) => {
		const entry = deepLinks.find((link) => link.schemaSlug === slug)
		expect(entry).toBeDefined()
		expect(entry.urlTemplate).toBe(template)
		expect(nl[entry.displayName], `${entry.displayName} has a Dutch name`).toBeTruthy()
	})
})
