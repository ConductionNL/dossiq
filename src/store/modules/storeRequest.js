/**
 * The JSON request, answer and loading guard the settings stores share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { generateUrl } from '@nextcloud/router'

/**
 * Ask one of dossiq's endpoints, with the headers every store call carries.
 *
 * @param {string} path The app-relative path, e.g. `/apps/dossiq/api/settings`.
 * @param {{method: string, body: (object|undefined)}} request The method and, for a write, what it sends.
 * @return {Promise<Response>} The response.
 */
export function jsonRequest(path, request) {
	const init = {
		method: request.method,
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
			'OCS-APIREQUEST': 'true',
		},
	}
	if (request.body !== undefined) {
		init.body = JSON.stringify(request.body)
	}
	return fetch(generateUrl(path), init)
}

/**
 * The body of a response, or the error a refused one is.
 *
 * @param {Response} response The response.
 * @param {string} failure The error's opening words, e.g. `Failed to save settings`.
 * @return {Promise<object>} The parsed body.
 */
export async function jsonAnswer(response, failure) {
	if (!response.ok) {
		throw new Error(`${failure}: ${response.statusText}`)
	}
	return response.json()
}

/**
 * Run one store call with the store's loading and error state around it.
 *
 * @param {object} store The store.
 * @param {{work: function(): Promise<object>, failed: function(Error): void}} call The work, and what to say when it fails.
 * @return {Promise<object|null>} What the work answered, or null when it failed.
 */
export async function guarded(store, call) {
	store.loading = true
	store.error = null
	try {
		return await call.work()
	} catch (error) {
		store.error = error.message
		call.failed(error)
		return null
	} finally {
		store.loading = false
	}
}
