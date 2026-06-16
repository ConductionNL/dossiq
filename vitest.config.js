/**
 * SPDX-FileCopyrightText: 2026 Conduction / Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for Procest frontend unit tests.
 *
 * The current suite targets PURE calc/util logic under `src/utils/**` (SLA
 * compliance, processing-time distribution, ISO 8601 duration parsing) that
 * is exercised end-to-end only through rendered dashboards — the exact
 * computed numbers are not asserted anywhere else. These functions need no
 * DOM, so the environment is `node`.
 *
 * `@nextcloud/l10n` is aliased to a deterministic stub (English source string
 * + {placeholder} substitution) so translated output is assertable. To add
 * component (`.vue`) tests later, switch to the jsdom environment and add
 * `@vitejs/plugin-vue2` + a css-noop plugin (see launchpad/vitest.config.js).
 */

const path = require('path')

module.exports = {
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: ['tests/e2e/**', 'tests/zgw/**', 'tests/Unit/**', 'tests/unit/**', 'node_modules/**'],
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			{
				find: /^@nextcloud\/l10n$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-l10n.js'),
			},
			{
				find: /^@nextcloud\/axios$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-axios.js'),
			},
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-router.js'),
			},
		],
	},
}
