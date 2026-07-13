/**
 * SPDX-FileCopyrightText: 2026 Conduction / Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for Procest frontend unit tests.
 *
 * The bulk of the suite targets PURE calc/util logic under `src/utils/**`
 * (SLA compliance, processing-time distribution, ISO 8601 duration parsing,
 * workflow-graph validation) that is exercised end-to-end only through
 * rendered dashboards — the exact computed numbers are not asserted
 * anywhere else. These functions need no DOM, so the DEFAULT environment is
 * `node`.
 *
 * A small number of component smoke tests (workflow editor: renders a
 * definition, blocks save on invalid) need a real DOM + Vue 2 SFC
 * compilation. Rather than switching every test to the heavier jsdom
 * environment, those spec files opt in per-file via a
 * `// @vitest-environment jsdom` pragma comment (Vitest reads this from the
 * file itself) — see `tests/vitest/workflowEditorSmoke.spec.js`. The Vue 2
 * SFC plugin + a CSS-noop plugin (recipe mirrors launchpad/vitest.config.js,
 * the sibling app that already solved `.vue` + `@nextcloud/vue` CSS
 * side-effect imports under Vitest) are registered unconditionally — they
 * are inert for the node-environment tests, which never import a `.vue`
 * file.
 *
 * `@nextcloud/l10n` is aliased to a deterministic stub (English source string
 * + {placeholder} substitution) so translated output is assertable.
 */

const path = require('path')
const vue2 = require('@vitejs/plugin-vue2')

/**
 * Side-effect imports of `*.css` from `@nextcloud/vue` (and friends) crash
 * Vite's transform pipeline because those CSS files don't exist on disk —
 * they are produced by a parallel vite build and referenced via tree-shaken
 * `import './foo.css'` lines that survive transpilation. A small plugin
 * intercepts `*.css` resolution and returns a virtual empty module so unit
 * tests can mount components without ever loading a stylesheet.
 */
const cssNoop = {
	name: 'procest-css-noop',
	enforce: 'pre',
	resolveId(id) {
		if (typeof id === 'string' && /\.css(\?.*)?$/.test(id)) {
			return '\0virtual:css-noop'
		}
		return null
	},
	load(id) {
		if (id === '\0virtual:css-noop') {
			return 'export default {}'
		}
		return null
	},
}

module.exports = {
	plugins: [
		cssNoop,
		vue2.default ? vue2.default() : vue2(),
	],
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: ['tests/e2e/**', 'tests/zgw/**', 'tests/Unit/**', 'tests/unit/**', 'node_modules/**'],
		setupFiles: [path.resolve(__dirname, 'tests/vitest/setup.js')],
		server: {
			deps: {
				// Inline Vue 2 + Nextcloud packages so Vite transforms their
				// .css side-effect imports through the cssNoop plugin above,
				// for the (jsdom-opted-in) component specs that import them.
				inline: [
					/@nextcloud\/vue/,
					/vue-material-design-icons/,
				],
			},
		},
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
			{
				find: /^@conduction\/nextcloud-vue$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/conduction-nextcloud-vue.js'),
			},
		],
	},
}
