// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The split picker reads the rule before it draws.
 *
 * 🔴 THE RULE IS NOT COPIED INTO THE BROWSER. It is asked for. A second copy
 * here would be a second answer, and the first time the two disagreed a
 * handler would be told they may divide something the server refuses. So what
 * is asserted is that the dialog ASKS, and that nothing decides the question
 * locally.
 *
 * 🔴 TWO EMPTY STATES, TWO SENTENCES. "This case type allows nothing to be
 * divided" and "this case holds nothing of what it allows" are different facts
 * with different remedies: the first is a call to an administrator, the second
 * is a case with no file on it yet. One sentence over both sent the second
 * handler to the first handler's meeting, which is what this change fixes.
 *
 * @spec openspec/changes/split-picker-asks-the-policy/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const dialog = fs.readFileSync(
	path.join(ROOT, 'src', 'dialogs', 'CaseSplitDialog.vue'),
	'utf8',
)
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const executor = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'Cases', 'CaseSplitExecutor.php'),
	'utf8',
)

describe('the picker asks what may be divided', () => {
	it('asks the server, at a url a route answers', () => {
		expect(dialog).toContain(
			'/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/split',
		)
		expect(routes).toContain("'name' => 'caseSplit#divisible'")
		expect(routes).toContain(
			"'url' => '/api/case/{caseId}/split',                           'verb' => 'GET'",
		)
	})

	it('carries no copy of the rule', () => {
		// The declaration's name belongs to the case type and the policy. A
		// dialog that read it would be deciding, not asking.
		expect(dialog).not.toContain('splittableParts')
		expect(executor).toContain('$this->policy->allowedFor(')
	})

	it('starts with nothing allowed, so no forbidden section is ever drawn', () => {
		// Not all three and then narrowed: a section drawn and removed is a
		// section that can be clicked in between.
		expect(dialog).toContain('allowed: [],')
	})

	it('gates every section on the answer', () => {
		expect(dialog).toContain("v-if=\"allows('documents') && documents.length > 0\"")
		expect(dialog).toContain("v-if=\"allows('parties') && parties.length > 0\"")
	})

	it('does not read a part it may not offer', () => {
		// One fewer request, but the reason is not speed: rows nobody may tick
		// have no business arriving in a browser at all.
		expect(dialog).toContain("this.allows('documents')")
		expect(dialog).toContain("this.allows('parties')")
	})

	it('tells the two empty states apart', () => {
		expect(dialog).toContain('data-testid="case-split-forbidden"')
		expect(dialog).toContain('data-testid="case-split-empty"')
		expect(dialog).toContain(
			'This case type does not allow a split to divide anything.',
		)
		expect(dialog).toContain(
			'This case holds nothing of the kinds a split may divide here.',
		)
	})

	it('reaches the forbidden sentence only when nothing is allowed', () => {
		expect(dialog).toContain('v-if="allowed.length === 0"')
		expect(dialog).toContain('v-else-if="nothingToDivide"')
	})
})
