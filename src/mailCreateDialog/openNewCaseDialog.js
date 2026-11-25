// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Mount dossiq's New case dialog on Mail's page for OpenRegister's Mail panel
 * and resolve with the created case, or with null when the person cancels.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */

import { useObjectStore } from '@conduction/nextcloud-vue'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import MailNewCaseHost from './MailNewCaseHost.vue'
import bundledManifest from '../manifest.json'
import pinia from '../pinia.js'
import registry from '../registry.js'
import { mailAnswersFor } from './mailAnswers.js'
import { actionFromMail, findNewCaseAction } from './newCaseAction.js'
import { prefetchFormSchema } from './prefetchFormSchema.js'

import '@conduction/nextcloud-vue/css/index.css'

/**
 * Open the dialog.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 * @param {{initialData: object, mail: {values: object}}} context What the Mail panel passes: the values filled from the mail's template, and the mail's own values.
 * @return {Promise<object|null>} The created case, or null.
 */
export async function openNewCaseDialog(context) {
	const action = findNewCaseAction(bundledManifest)
	if (!action) {
		throw new Error('dossiq has no New case action')
	}
	// The case type's questions and the form's schema are read side by side:
	// the dialog's own open reads the schema from the store, so it no longer
	// waits for the questions first.
	const [answers] = await Promise.all([
		mailAnswersFor(context?.initialData?.caseType, context?.mail?.values),
		prefetchFormSchema(useObjectStore(pinia), action),
	])
	return new Promise((resolve) => {
		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp({
			render: () => h(MailNewCaseHost, {
				action: actionFromMail(action, { ...(context?.initialData || {}), ...answers }),
				onDone: (created) => {
					app.unmount()
					host.remove()
					resolve(created || null)
				},
			}),
		})
		app.use(pinia)
		// What CnAppRoot provides on dossiq's own pages: the board look, the
		// manifest the form's pickers read, the registry and the translations.
		app.provide('cnLook', bundledManifest.look || 'nextcloud')
		app.provide('cnManifest', bundledManifest)
		app.provide('cnRegistry', registry)
		app.provide('cnTranslate', (key) => t('dossiq', key))
		app.config.globalProperties.t = t
		app.config.globalProperties.n = n
		app.mount(host)
	})
}
