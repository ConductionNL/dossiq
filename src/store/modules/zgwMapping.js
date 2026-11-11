import { defineStore } from 'pinia'
import { guarded, jsonAnswer, jsonRequest } from './storeRequest.js'

const MAPPINGS = '/apps/dossiq/api/zgw-mappings'

export const useZgwMappingStore = defineStore('zgwMapping', {
	state: () => ({
		mappings: {},
		loading: false,
		error: null,
	}),
	getters: {
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getMappings: (state) => state.mappings,
	},
	actions: {
		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		async fetchMappings() {
			return guarded(this, {
				work: async () => {
					const data = await jsonAnswer(await jsonRequest(MAPPINGS, { method: 'GET' }), 'Failed to fetch ZGW mappings')
					this.mappings = data.mappings || {}
					return this.mappings
				},
				failed: (error) => console.error('Error fetching ZGW mappings:', error),
			})
		},

		/**
		 * @param {string} resourceKey The resource key.
		 * @param {object} config The config.
		 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md
		 */
		async saveMapping(resourceKey, config) {
			return guarded(this, {
				work: async () => {
					const response = await jsonRequest(`${MAPPINGS}/${resourceKey}`, { method: 'PUT', body: config })
					const data = await jsonAnswer(response, 'Failed to save ZGW mapping')
					this.mappings[resourceKey] = data.mapping
					return data.mapping
				},
				failed: (error) => console.error('Error saving ZGW mapping:', error),
			})
		},

		/**
		 * @param {string} resourceKey The resource key.
		 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md
		 */
		async resetMapping(resourceKey) {
			return guarded(this, {
				work: async () => {
					const response = await jsonRequest(`${MAPPINGS}/${resourceKey}/reset`, { method: 'POST' })
					const data = await jsonAnswer(response, 'Failed to reset ZGW mapping')
					this.mappings[resourceKey] = data.mapping
					return data.mapping
				},
				failed: (error) => console.error('Error resetting ZGW mapping:', error),
			})
		},
	},
})
