// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The countdown tile's headline must use the readable foreground tokens.
 *
 * The defect this pins down: CnCountdownWidget writes
 * `color: var(--color-error)` inline, and on Nextcloud 34 `--color-error`
 * is a FILL token (#FFE7E7), so "26 days overdue" rendered as pale pink on
 * white. The readable variants are the `-text` tokens, which CnStatWidget
 * already uses. The widget's inline style cannot be overridden by a class
 * without `!important`, but the token it references CAN be redefined on the
 * widget root, which is what `src/assets/app.css` does per variant. This
 * checks that each variant root repoints its token at the `-text` variant,
 * and that the override stays scoped to the widget.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const css = readFileSync(path.resolve(__dirname, '../../src/assets/app.css'), 'utf8')

/**
 * The declarations inside the rule whose selector list is exactly `selector`.
 *
 * @param {string} selector The selector to look up.
 * @return {string} The rule body, or '' when the rule is absent.
 */
function ruleBody(selector) {
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const match = new RegExp(`${escaped}\\s*\\{([^}]*)\\}`).exec(css)
	return match ? match[1] : ''
}

describe('countdown colour tokens', () => {
	for (const variant of ['error', 'warning', 'success']) {
		it(`repoints --color-${variant} at its -text token on the ${variant} variant root`, () => {
			const body = ruleBody(`.cn-countdown-widget--${variant}`)
			expect(body, `a rule for .cn-countdown-widget--${variant}`).not.toBe('')
			expect(body).toMatch(
				new RegExp(`--color-${variant}:\\s*var\\(--color-${variant}-text`),
			)
		})
	}

	it('does not repoint the tokens anywhere wider than the widget root', () => {
		const repointed = [
			...css.matchAll(/^([^{\n]+)\{[^}]*--color-(?:error|warning|success):/gm),
		].map((m) => m[1].trim())
		expect(repointed.length).toBeGreaterThan(0)
		for (const selector of repointed) {
			expect(selector).toMatch(
				/^\.cn-countdown-widget--(error|warning|success)$/,
			)
		}
	})
})
