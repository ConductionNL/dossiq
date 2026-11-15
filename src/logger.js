// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The app's logger, and the one place `no-console` is switched off.
 *
 * Before this module the app had no logger, so every place that needed to say
 * "this read threw and the empty list you are looking at is not the truth"
 * called `console.warn` or `console.error` where it stood, and each of those
 * five call sites carried its own `eslint-disable-next-line no-console`. Five
 * exceptions to one rule are five places the rule no longer applies, and a
 * sixth was added without anyone noticing. Here the exception is written once,
 * next to the reason for it, and the rule holds everywhere else in `src/`.
 *
 * Use it for anything an operator would need to see. A caught error that
 * leaves the interface showing nothing is the case this exists for: an empty
 * list and a failed read look identical on the page, and only the log tells
 * them apart.
 *
 * `@nextcloud/logger` was the obvious alternative and it is not usable here.
 * Importing it pulls in `@nextcloud/auth`, which reads `window` while the
 * module is still evaluating, so any unit test that runs in the node
 * environment fails on the import rather than on anything it meant to check.
 * Measured on `tests/vitest/casesOnMap.spec.js`: five tests, all red with
 * `ReferenceError: window is not defined`.
 */

/* eslint-disable no-console */

/**
 * Write one line, tagged with the app and whatever context came with it.
 *
 * @param {'debug'|'info'|'warn'|'error'} level The console method to use.
 * @param {string} message What happened.
 * @param {object} [context] Anything worth having beside it, e.g. `{ error }`.
 * @return {void}
 */
function write(level, message, context) {
	const line = `[dossiq] ${message}`
	if (context === undefined) {
		console[level](line)
		return
	}

	console[level](line, context)
}

export default {
	debug: (message, context) => write('debug', message, context),
	info: (message, context) => write('info', message, context),
	warn: (message, context) => write('warn', message, context),
	error: (message, context) => write('error', message, context),
}
