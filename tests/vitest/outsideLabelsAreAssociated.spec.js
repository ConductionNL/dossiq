/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A field that draws its label outside itself (`labelOutside`) shows no label
 * of its own, so it needs a <label for> pointing at its id, or a screen reader
 * announces an unnamed text box (WCAG 1.3.1, 4.1.2).
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const FILES = [
	'src/dialogs/AssessmentDecisionDialog.vue',
	'src/dialogs/BerichtenboxComposeDialog.vue',
	'src/components/case/CaseActFields.vue',
]

/**
 * Every text field or text area in a template that sets labelOutside.
 *
 * @param {string} source The component source.
 * @return {Array<string>} The opening tags.
 */
function outsideLabelledFields(source) {
	const tags = source.match(/<Nc(TextField|TextArea)\b[^>]*>/g) ?? []
	return tags.filter((tag) => tag.includes(':labelOutside="true"'))
}

describe('a field with its label outside carries an associated label', () => {
	for (const file of FILES) {
		it(file, () => {
			const source = fs.readFileSync(path.join(ROOT, file), 'utf8')
			const fields = outsideLabelledFields(source)
			expect(fields.length).toBeGreaterThan(0)
			for (const field of fields) {
				const id = field.match(/\s:?id="([^"]+)"/)
				expect(id, `${file}: ${field.slice(0, 60)} has no id`).toBeTruthy()
				expect(source, `${file}: no <label> for ${id[1]}`).toContain(`for="${id[1]}"`)
			}
		})
	}
})
