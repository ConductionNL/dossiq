/**
 * Advice store module for Dossiq VTH.
 *
 * Manages advice requests (adviesAanvragen) with internal/external
 * advice lifecycle, deadline tracking, and escalation.
 */
import { defineStore } from 'pinia'
import { useEngineTaskStore } from './engineTask.js'
import { useObjectStore } from './object.js'

/**
 * Save a new advice request, keep it in the store and, for an internal
 * request with an advisor, create the advisor's task.
 *
 * @param {object} store The advice store.
 * @param {object} requestData The request data.
 * @return {Promise<object>} The saved request.
 */
async function saveNewRequest(store, requestData) {
	const objectStore = useObjectStore()
	const saved = await objectStore.saveObject('adviesAanvraag', {
		...requestData,
		status: 'requested',
		requestedAt: new Date().toISOString(),
	})
	store.requests.push(saved)

	if (requestData.type === 'intern' && requestData.advisor) {
		await createAdvisorTask(requestData)
	}
	return saved
}

/**
 * Create the task for the advisor of an internal advice request.
 *
 * @param {object} requestData The request data.
 * @return {Promise<object|null>} The created task.
 */
function createAdvisorTask(requestData) {
	return useEngineTaskStore().create({
		case: requestData.case,
		title: `Advies uitbrengen: ${requestData.subject || 'Adviesaanvraag'}`,
		description: requestData.questions || '',
		assignee: requestData.advisor,
		status: 'available',
		dueDate: requestData.deadline,
	})
}

/**
 * Save an advice request as expired, create the handler's follow-up task
 * and replace the request in the store.
 *
 * @param {object} store The advice store.
 * @param {string} requestId UUID of the request.
 * @return {Promise<object|null>} The updated request, or null when unknown.
 */
async function saveExpiredRequest(store, requestId) {
	const request = store.requests.find((r) => r.id === requestId)
	if (!request) {
		return null
	}

	const updated = await saveAsExpired(request)
	await createExpiryTask(request)
	const index = store.requests.findIndex((r) => r.id === requestId)
	if (index >= 0) {
		store.requests.splice(index, 1, updated)
	}
	return updated
}

/**
 * Save an advice request with the expired status.
 *
 * @param {object} request The advice request.
 * @return {Promise<object>} The updated request.
 */
function saveAsExpired(request) {
	const objectStore = useObjectStore()
	return objectStore.saveObject('adviesAanvraag', {
		...request,
		status: 'expired',
	})
}

/**
 * Create the handler's task for an advice request that expired.
 *
 * @param {object} request The advice request.
 * @return {Promise<object|null>} The created task.
 */
function createExpiryTask(request) {
	return useEngineTaskStore().create({
		case: request.case,
		title: `Advies verlopen: ${request.subject || request.advisor}`,
		description: `Advies van ${request.advisor} is verlopen. Beoordeel of procedure kan doorgaan zonder dit advies.`,
		status: 'available',
	})
}

export const useAdviceStore = defineStore('advice', {
	state: () => ({
		/** @type {Array} Advice requests for the current case */
		requests: [],
		/** @type {boolean} Loading state */
		loading: false,
		/** @type {string|null} Error message */
		error: null,
	}),

	getters: {
		/**
		 * Get pending advice requests.
		 *
		 * @param {object} state Store state
		 * @return {Array} Pending requests
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-advice-management/tasks.md
		 */
		pendingRequests(state) {
			return state.requests.filter((r) => r.status === 'requested')
		},

		/**
		 * Get overdue advice requests.
		 *
		 * @param {object} state Store state
		 * @return {Array} Overdue requests
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-advice-management/tasks.md
		 */
		overdueRequests(state) {
			const now = new Date()
			return state.requests.filter((r) => {
				if (r.status !== 'requested' || !r.deadline) {
					return false
				}
				return new Date(r.deadline) < now
			})
		},

		/**
		 * Get received advice requests.
		 *
		 * @param {object} state Store state
		 * @return {Array} Received requests
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-advice-management/tasks.md
		 */
		receivedRequests(state) {
			return state.requests.filter((r) => r.status === 'received')
		},

		/**
		 * Check if all advice has been received (no pending requests).
		 *
		 * @param {object} state Store state
		 * @return {boolean} True if all received
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-advice-management/tasks.md
		 */
		allAdviceReceived(state) {
			return (
				state.requests.length > 0
				&& state.requests.every(
					(r) => r.status === 'received' || r.status === 'expired',
				)
			)
		},
	},

	actions: {
		/**
		 * Fetch advice requests for a case.
		 *
		 * @param {string} caseId UUID of the case
		 * @return {Promise<Array>} Advice requests
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-advice-management/tasks.md
		 */
		async fetchRequests(caseId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const response = await objectStore.fetchCollection(
					'adviesAanvraag',
					{
						case: caseId,
						limit: 100,
					},
				)
				this.requests = response?.results || response || []
				return this.requests
			} catch (error) {
				this.error = error.message
				console.error('Error fetching advice requests:', error)
				return []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create an advice request.
		 *
		 * @param {object} requestData The request data
		 * @return {Promise<object|null>} Created request
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-advice-management/tasks.md
		 */
		async createRequest(requestData) {
			this.loading = true
			this.error = null
			try {
				return await saveNewRequest(this, requestData)
			} catch (error) {
				this.error = error.message
				console.error('Error creating advice request:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Mark an advice request as received.
		 *
		 * @param {string} requestId     UUID of the request
		 * @param {string} documentId    Nextcloud file ID of the advice document
		 * @return {Promise<object|null>} Updated request
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-advice-management/tasks.md
		 */
		async markReceived(requestId, documentId) {
			this.loading = true
			try {
				const request = this.requests.find((r) => r.id === requestId)
				if (!request) {
					return null
				}

				const objectStore = useObjectStore()
				const updated = await objectStore.saveObject('adviesAanvraag', {
					...request,
					status: 'received',
					receivedAt: new Date().toISOString(),
					adviceDocument: documentId || request.adviceDocument,
				})

				const index = this.requests.findIndex((r) => r.id === requestId)
				if (index >= 0) {
					this.requests.splice(index, 1, updated)
				}
				return updated
			} catch (error) {
				this.error = error.message
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Mark an advice request as expired.
		 *
		 * @param {string} requestId UUID of the request
		 * @return {Promise<object|null>} Updated request
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-advice-management/tasks.md
		 */
		async markExpired(requestId) {
			this.loading = true
			try {
				return await saveExpiredRequest(this, requestId)
			} catch (error) {
				this.error = error.message
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get days until deadline or days overdue.
		 *
		 * @param {object} request The advice request
		 * @return {number} Positive = days remaining, negative = days overdue
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-advice-management/tasks.md
		 */
		getDaysToDeadline(request) {
			if (!request.deadline) {
				return null
			}
			const deadline = new Date(request.deadline)
			const now = new Date()
			const diff = deadline - now
			return Math.ceil(diff / (1000 * 60 * 60 * 24))
		},
	},
})
