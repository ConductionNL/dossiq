/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "All cases on this object" opens the case list with
 * `_related[caseObject][case][objectUrl]`. The Active line names a filter by
 * its schema title, else by its key run through the app's translate, so the
 * key needs a catalogue entry or the chip reads the raw query.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const KEY = '_related[caseObject][case][objectUrl]'
const catalogue = (lang) => JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', `${lang}.json`), 'utf8')).translations

describe('the object filter chip on the case list', () => {
	it.each([
		['en', 'Object'],
		['nl', 'Object'],
	])('reads "%s: Object", not the raw query', (lang, label) => {
		expect(catalogue(lang)[KEY]).toBe(label)
		expect(fs.readFileSync(path.join(ROOT, 'l10n', `${lang}.js`), 'utf8')).toContain(`"${KEY}": "${label}"`)
	})
})
