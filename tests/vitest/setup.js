/**
 * SPDX-FileCopyrightText: 2026 Conduction / Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Global setup for the Vitest unit suite. Stubs the Nextcloud `t`/`n`
 * globals so component renders that call them (bare `t('procest', ...)`,
 * the convention used throughout `src/`) resolve without the real
 * `@nextcloud/webpack-vue-config` ProvidePlugin, which does not run under
 * Vitest.
 *
 * Registered two ways because Vue 2's template compiler emits `_vm.t(...)`
 * (looked up on the component instance), while plain script code calls the
 * bare `t(...)` (looked up on the global): a global Vue mixin puts `t`/`n`
 * on every component instance's `this`, and `globalThis.t`/`globalThis.n`
 * cover direct script-level calls (store actions, utils).
 *
 * Loaded for every spec file regardless of its `@vitest-environment`
 * pragma (see `vitest.config.js` `test.setupFiles`) — it is a no-op for the
 * pure-logic (node-environment) tests that never touch `t`/`n` this way,
 * and required for the jsdom-environment component smoke tests.
 */

import Vue from 'vue'
import { translate, translatePlural } from './stubs/nextcloud-l10n.js'

globalThis.t = translate
globalThis.n = translatePlural

Vue.mixin({
	methods: {
		t: translate,
		n: translatePlural,
	},
})
