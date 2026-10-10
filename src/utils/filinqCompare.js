// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Filinq's compare view, from dossiq (woo-delivered-set-is-a-record REQ-WDS-004).
//
// Filinq ships the original/delivered split view as a self-contained bundle,
// `js/filinq-compare.js`, which sets `OCA.Filinq.mountCompare(el, { original,
// delivered, labels })` and returns a handle with `unmount()` (filinq
// anonymization-review-workbench REQ-DDARW-014). Dossiq loads it only when a
// compare dialog opens, and only when filinq is installed.

import { generateFilePath } from '@nextcloud/router'

let loading = null

/**
 * Is filinq installed on this instance?
 *
 * `OC.appswebroots` is keyed by installed app id: the same duck-typed question
 * `IAppManager::isInstalled()` answers on the server. Read per call, never at
 * module load.
 *
 * @param {object} [roots] The app web roots; defaults to `OC.appswebroots`.
 * @return {boolean} True when filinq is installed.
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
 */
export function isFilinqInstalled(roots = globalThis.OC?.appswebroots ?? {}) {
	return Object.hasOwn(roots || {}, 'filinq')
}

/**
 * Load filinq's compare bundle once and answer its `mountCompare`, or null.
 *
 * Null means the view is not there: filinq is absent, the bundle did not load,
 * or it loaded without the function (an older filinq). The caller then says
 * the compare view needs filinq; it never draws something that looks like a
 * comparison.
 *
 * @param {object} [env] `{ win, doc, roots }`, for tests.
 * @return {Promise<(function(HTMLElement, object): {unmount: function(): void})|null>} `mountCompare(el, options)`, or null.
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
 */
export function loadFilinqCompare(env = {}) {
	const win = env.win || window
	const doc = env.doc || document
	const found = () => win.OCA?.Filinq?.mountCompare || null
	if (!isFilinqInstalled(env.roots)) {
		return Promise.resolve(null)
	}
	if (found()) {
		return Promise.resolve(found())
	}
	if (!loading) {
		loading = new Promise((resolve) => {
			const script = doc.createElement('script')
			script.src = generateFilePath('filinq', 'js', 'filinq-compare.js')
			script.async = true
			if (win.OC?.requestToken) {
				script.nonce = btoa(win.OC.requestToken)
			}
			script.onload = () => resolve(found())
			script.onerror = () => {
				// Let a later dialog try again: the failure may be transient.
				loading = null
				resolve(null)
			}
			doc.head.appendChild(script)
		})
	}
	return loading
}

/**
 * Forget a load in progress. For tests.
 *
 * @return {void}
 */
export function resetFilinqCompare() {
	loading = null
}
