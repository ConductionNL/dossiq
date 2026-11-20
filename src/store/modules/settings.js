import { defineStore } from 'pinia'
import { guarded, jsonAnswer, jsonRequest } from './storeRequest.js'

const SETTINGS = '/apps/dossiq/api/settings'

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
		/** @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-admin-settings/tasks.md */
		async fetchSettings() {
			return guarded(this, {
				work: async () => {
					const data = await jsonAnswer(await jsonRequest(SETTINGS, { method: 'GET' }), 'Failed to fetch settings')
					this.config = data.config || data
					this.openRegisters = data.openRegisters ?? false
					this.isAdmin = data.isAdmin ?? false
					this.initialized = true

					return this.config
				},
				failed: (error) => console.error('Error fetching Dossiq settings:', error),
			})
		},

		/**
		 * @param {object} settingsData The settings data.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-admin-settings/tasks.md
		 */
		async saveSettings(settingsData) {
			return guarded(this, {
				work: async () => {
					const data = await jsonAnswer(await jsonRequest(SETTINGS, { method: 'POST', body: settingsData }), 'Failed to save settings')
					this.config = data.config || data

					return this.config
				},
				failed: (error) => console.error('Error saving Dossiq settings:', error),
			})
		},
	},
})
