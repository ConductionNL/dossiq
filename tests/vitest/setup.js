/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Global setup for the Vitest unit suite. Stubs the Nextcloud `t`/`n`
 * globals so component renders that call them (bare `t('dossiq', ...)`,
 * the convention used throughout `src/`) resolve without the real
 * `@nextcloud/webpack-vue-config` ProvidePlugin, which does not run under
 * Vitest.
 *
 * Registered two ways because a compiled template resolves `t(...)` against
 * the render context (component instance / its `methods`), while plain
 * script code calls the bare `t(...)` resolved against the global: a global
 * mixin puts `t`/`n` on every mounted component instance's `this`, and
 * `globalThis.t`/`globalThis.n` cover direct script-level calls (store
 * actions, utils).
 *
 * VUE 3 / VTU 2 NOTE — this file previously did:
 *
 *     import Vue from 'vue'
 *     Vue.mixin({ methods: { t, n } })
 *
 * `vue@3` publishes NO default export, so `Vue` was `undefined` and the
 * `.mixin` call threw. Because Vitest evaluates `setupFiles` before every
 * spec, that single line errored the collection of ALL 32 spec files —
 * the suite reported "Tests: no tests" and had never actually run since the
 * Vue 3 migration.
 *
 * Under Vue 3 there is no global `Vue` constructor to mix into; a global
 * mixin is applied per-app. The Vue-3 equivalent for a test harness is
 * `@vue/test-utils`' `config.global.mixins`, which VTU applies to the app
 * it creates for each `mount()`/`shallowMount()`. Importing `config` from
 * `@vue/test-utils` is safe in the node-environment (pure-logic) specs too:
 * it touches no DOM at import time.
 *
 * Loaded for every spec file regardless of its `@vitest-environment`
 * pragma (see `vitest.config.js` `test.setupFiles`) — it is a no-op for the
 * pure-logic (node-environment) tests that never touch `t`/`n` this way,
 * and required for the jsdom-environment component smoke tests.
 */

import { config } from '@vue/test-utils'
import { translate, translatePlural } from './stubs/nextcloud-l10n.js'

globalThis.t = translate
globalThis.n = translatePlural

config.global.mixins = [
	...(config.global.mixins || []),
	{
		methods: {
			t: translate,
			n: translatePlural,
		},
	},
]

// MEASURED, so nobody mistakes this for a safety net that is being
// exercised: replacing the two `globalThis` bindings above with a sentinel
// reds 3 tests (workflowGraphValidation ORPHAN_NODE / UNREACHABLE_FINAL and
// workflowEditorSmoke's NO_FINAL_STATUS case) — they are load-bearing.
// Replacing the MIXIN's `t`/`n` with a sentinel reds nothing: as of this
// commit no spec resolves `t` through a component instance. The mixin is
// kept because it is the faithful Vue-3/VTU-2 equivalent of what the old
// `Vue.mixin()` call intended and it costs nothing, but it is currently
// unexercised — do not treat its presence as evidence that instance-level
// `t` works.
