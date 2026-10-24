/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lifecycle menu's options span the dialog's content, as board
 * DqVolgendeStap draws them (592 wide). The dialog already pads its content;
 * the menu's own 16px at the sides made them 560 (r24).
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/CaseLifecycleMenuDialog.vue'), 'utf8')
const style = source.slice(source.indexOf('<style'))

describe('the lifecycle menu body', () => {
	it('adds no padding at the sides of the dialog content', () => {
		const at = style.indexOf('.case-acts {')
		const body = style.slice(at, style.indexOf('}', at))
		expect(body).toContain('padding: 8px 0 16px;')
	})
})
