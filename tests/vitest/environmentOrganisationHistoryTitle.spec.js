/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisation page's history card reads "Historie", as board DqTenant
 * and the contact and organisation record pages say.
 *
 * @spec openspec/specs/environment-organisation-page/spec.md#requirement-the-organisation-history-card-reads-historie
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {string} file Path under the repository root.
 * @return {object} The parsed document.
 */
function readJson(file) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
}

describe('organisation on this environment: history card', () => {
	it('is titled with a string that reads Historie in Dutch', () => {
		const page = readJson('src/manifest.d/67-environment-organisations.json').pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail')
		const card = page.config.sideColumn.find((entry) => entry.id === 'organisation-side-history')
		expect(readJson('l10n/nl.json').translations[card.title]).toBe('Historie')
	})
})
