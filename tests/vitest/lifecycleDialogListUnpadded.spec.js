/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lifecycle dialog's list of acts adds no padding of its own to the
 * dialog body, as DqVolgendeStap draws it (r30: the footer stood 24px low).
 * Read from the component.
 *
 * @spec openspec/changes/lifecycle-dialog-list-unpadded/specs/case-management/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/CaseLifecycleMenuDialog.vue'), 'utf8')
const style = source.slice(source.indexOf('>', source.indexOf('<style')) + 1).replace(/\/\*[\s\S]*?\*\//g, '')

describe('CaseLifecycleMenuDialog list', () => {
	it('adds no padding to the dialog body', () => {
		const m = style.match(/(^|\n)\.case-acts \{([^{}]*)\}/)
		expect(m).not.toBeNull()
		expect(m[2]).not.toMatch(/padding/)
	})
})
