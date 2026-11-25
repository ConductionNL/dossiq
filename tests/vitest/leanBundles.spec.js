// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The production webpack config goes through nextcloud-vue's withLeanBundles:
 * css-loader writes no source maps into the JS (they put 483 KB of CSS
 * mappings into dossiq-shared-nc-vue.js) and no cache group takes async
 * chunks, so the library's lazy chunks stay lazy.
 */
import { createRequire } from 'node:module'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)

describe('webpack.config.js lean bundles', () => {
	const config = require('../../webpack.config.js')

	const cssLoaders = (rules) => rules.flatMap((rule) => (Array.isArray(rule.use) ? rule.use : []))
		.filter((entry) => (typeof entry === 'string' ? entry : entry.loader) === 'css-loader')

	it('turns css-loader source maps off', () => {
		const loaders = cssLoaders(config.module.rules)
		expect(loaders.length).toBeGreaterThan(0)
		for (const loader of loaders) {
			expect(loader.options.sourceMap).toBe(false)
		}
	})

	it('keeps every shared cache group to initial chunks', () => {
		const split = config.optimization.splitChunks
		expect(split.chunks).toBe('initial')
		for (const group of Object.values(split.cacheGroups).filter(Boolean)) {
			expect(group.chunks).toBe('initial')
		}
	})
})
