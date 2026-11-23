// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Mount dossiq's New case dialog on Mail's page for OpenRegister's Mail panel
 * and resolve with the created case, or with null when the person cancels.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */

import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import MailNewCaseHost from './MailNewCaseHost.vue'
import bundledManifest from '../manifest.json'
import pinia from '../pinia.js'
import registry from '../registry.js'
import { actionFromMail, findNewCaseAction } from './newCaseAction.js'

import '@conduction/nextcloud-vue/css/index.css'

/**
 * Open the dialog.
 *
 * @param {{initialData: object}} context What the Mail panel passes: the values filled from the mail.
 * @return {Promise<object|null>} The created case, or null.
 */
export function openNewCaseDialog(context) {
	const action = findNewCaseAction(bundledManifest)
	if (!action) {
		return Promise.reject(new Error('dossiq has no New case action'))
	}
	return new Promise((resolve) => {
		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp({
			render: () => h(MailNewCaseHost, {
				action: actionFromMail(action, context?.initialData),
				onDone: (created) => {
					app.unmount()
					host.remove()
					resolve(created || null)
				},
			}),
		})
		app.use(pinia)
		app.provide('cnRegistry', registry)
		app.provide('cnTranslate', (key) => t('dossiq', key))
		app.config.globalProperties.t = t
		app.config.globalProperties.n = n
		app.mount(host)
	})
}
