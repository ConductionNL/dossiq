/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two boot-time rules that are true today and were checked by nothing.
 *
 * Both hold on the current tree, so neither of these tests is fixing a defect.
 * They exist because both rules fail SILENTLY when they stop holding. A
 * production build that emits a full `'source-map'` ships a `.js.map` exposing
 * the original unminified source next to the publicly served bundle, and the
 * app works perfectly while it does it. A manifest that stops being `markRaw`'d
 * makes Vue instrument a ~130KB navigation and widget tree with per-property
 * reactivity on every boot, and the app works perfectly while it does that too.
 * Neither raises, neither shows up in a screenshot, and no e2e assertion can
 * see either one.
 *
 * `performance-hardening` has five scenarios and gate-19 reports all five
 * uncovered. These are the two that are claims about source files rather than
 * runtime behaviour, so they belong in a unit test. The other three are
 * audit-log pagination, server-side batch filtering and a bounded substitution
 * index, which are API behaviours and want a different instrument.
 *
 * @spec openspec/specs/performance-hardening/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const webpackConfig = fs.readFileSync(
	path.join(ROOT, 'webpack.config.js'),
	'utf8',
)
const mainSource = fs.readFileSync(path.join(ROOT, 'src', 'main.js'), 'utf8')

/**
 * Devtool values that do NOT publish original source beside the bundle.
 *
 * `eval*` variants embed sources in the bundle itself, so they are excluded
 * here as well as `source-map` and its `inline`/`hidden` relatives.
 */
const NON_EXPOSING = ['nosources-source-map', 'nosources-cheap-source-map', false]

describe('the production build does not ship readable source', () => {
	// @spec openspec/specs/performance-hardening/spec.md#production-devtool-is-not-full-source-map
	it('resolves a devtool that publishes no original source', () => {
		// Read as source rather than by requiring the config: it pulls in
		// @nextcloud/webpack-vue-config and does not evaluate standalone.
		const assignment = webpackConfig.match(
			/webpackConfig\.devtool\s*=\s*(.+)/,
		)

		// 🔴 GUARD ON THE GUARD. If the assignment is ever rewritten into an
		// if/else or moved into the base config, this regex stops matching, and
		// without this line the test would report green having inspected
		// nothing at all. That is the failure shape it exists to prevent, so it
		// must not be able to adopt it.
		expect(
			assignment,
			'webpack.config.js must assign webpackConfig.devtool in one '
				+ 'statement, or this test is no longer reading the value it claims '
				+ 'to check',
		).not.toBeNull()

		const expression = assignment[1]

		expect(
			expression.includes("'source-map'"),
			'the production devtool must not be the full source-map variant: it '
				+ 'emits a .js.map exposing original, unminified source alongside '
				+ 'the publicly served bundle',
		).toBe(false)

		// And positively: the production branch must be one of the variants
		// that publish no sources. Asserting only the absence above would pass
		// on `eval-source-map`, which embeds the sources in the bundle instead.
		expect(
			NON_EXPOSING.some((value) => expression.includes(`'${value}'`)),
			`the production devtool must be one of ${NON_EXPOSING.filter(Boolean).join(', ')}; `
				+ `webpack.config.js resolves it from: ${expression.trim()}`,
		).toBe(true)
	})
})

describe('the app manifest is not deeply reactive at boot', () => {
	// @spec openspec/specs/performance-hardening/spec.md#manifest-prop-is-markrawd
	it('wraps the manifest in markRaw before passing it as a prop', () => {
		expect(
			mainSource.includes('markRaw'),
			'src/main.js must import and use markRaw',
		).toBe(true)

		// The scenario's THEN is about the value ASSIGNED AS THE PROP, not
		// merely that markRaw appears somewhere in the file. Building the
		// manifest with markRaw and then handing the render tree a reactive
		// copy would satisfy a looser check and none of the requirement.
		//
		// 🔴 `manifest:` ALSO APPEARS AS A DESTRUCTURING RENAME, and matching
		// the first occurrence finds that one instead. The first draft of this
		// test did exactly that and failed against a tree that satisfies the
		// requirement: `const { manifest: resolvedManifest } = useAppManifest(…)`
		// is a rename, not a prop. Destructuring lines are dropped here, and
		// every surviving assignment has to be wrapped, so a second unwrapped
		// prop cannot hide behind a first wrapped one.
		const assignments = mainSource
			.split('\n')
			.filter((line) => /\bmanifest:\s*\S/.test(line))
			.filter((line) => line.includes('const {') === false)

		expect(
			assignments.length,
			'src/main.js must pass a `manifest:` prop into the root component, '
				+ 'or this test is checking a prop that no longer exists',
		).toBeGreaterThan(0)

		expect(
			assignments.filter((line) => line.includes('markRaw(') === false),
			'every manifest prop must be wrapped in markRaw() at assignment: '
				+ 'without it Vue instruments the whole pages, menu and widget '
				+ 'tree with per-property reactivity on every boot, which costs '
				+ 'boot time and shows up as nothing at all',
		).toEqual([])
	})
})
