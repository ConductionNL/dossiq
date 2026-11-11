import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/**
 * Ask the settings endpoint, with the headers every settings call carries.
 *
 * @param {string} method GET or POST.
 * @param {object} [body] What a POST sends.
 * @return {Promise<Response>} The response.
 */
function settingsRequest(method, body) {
	const init = {
		method,
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
			'OCS-APIREQUEST': 'true',
		},
	}
	if (body !== undefined) {
		init.body = JSON.stringify(body)
	}
	return fetch(generateUrl('/apps/dossiq/api/settings'), init)
}

/**
 * The body of a settings response, or the error a refused one is.
 *
 * @param {Response} response The response.
 * @param {string} verb What was asked, for the error: fetch or save.
 * @return {Promise<object>} The parsed body.
 */
async function settingsAnswer(response, verb) {
	if (!response.ok) {
		throw new Error(`Failed to ${verb} settings: ${response.statusText}`)
	}
	return response.json()
}

/**
 * Run one settings call with the store's loading and error state around it.
 *
 * @param {object} store The settings store.
 * @param {{doing: string, work: function(): Promise<object>}} call What is being done, for the log, and the work itself.
 * @return {Promise<object|null>} What the work answered, or null when it failed.
 */
async function guarded(store, call) {
	store.loading = true
	store.error = null
	try {
		return await call.work()
	} catch (error) {
		store.error = error.message
		console.error(`Error ${call.doing} Dossiq settings:`, error)
		return null
	} finally {
		store.loading = false
	}
}

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		config: null,
		openRegisters: false,
		isAdmin: false,
		loading: false,
		error: null,
		initialized: false,
	}),
	getters: {
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		isInitialized: (state) => state.initialized,
		getConfig: (state) => state.config,
		hasOpenRegisters: (state) => state.openRegisters,
		getIsAdmin: (state) => state.isAdmin,
	},
	actions: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async fetchSettings() {
			return guarded(this, {
				doing: 'fetching',
				work: async () => {
					const data = await settingsAnswer(await settingsRequest('GET'), 'fetch')
					this.config = data.config || data
					this.openRegisters = data.openRegisters ?? false
					this.isAdmin = data.isAdmin ?? false
					this.initialized = true

					return this.config
				},
			})
		},

		/**
		 * @param {object} settingsData The settings data.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		async saveSettings(settingsData) {
			return guarded(this, {
				doing: 'saving',
				work: async () => {
					const data = await settingsAnswer(await settingsRequest('POST', settingsData), 'save')
					this.config = data.config || data

					return this.config
				},
			})
		},
	},
})
