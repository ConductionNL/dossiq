/**
 * Enforcement store module for Procest VTH.
 *
 * Manages enforcement actions (handhavingsacties), LHS matrix lookup,
 * dwangsom tracking, and begunstigingstermijn management.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

/**
 * Default LHS matrix (Landelijke Handhavingsstrategie).
 * Used when no custom matrix is configured.
 */
const DEFAULT_LHS_MATRIX = {
	gering: {
		goedwillend: 'Waarschuwing',
		onverschillig: 'Waarschuwing + herstel',
		calculerend: 'Last onder dwangsom',
		crimineel: 'PV + Last',
	},
	aanzienlijk: {
		goedwillend: 'Herstelactie',
		onverschillig: 'Last onder dwangsom',
		calculerend: 'Last + PV',
		crimineel: 'PV + Bestuursdwang',
	},
	ernstig: {
		goedwillend: 'Last onder dwangsom',
		onverschillig: 'Last + PV',
		calculerend: 'PV + Bestuursdwang',
		crimineel: 'PV + Bestuursdwang',
	},
}

export const useEnforcementStore = defineStore('enforcement', {
	state: () => ({
		/** @type {Array} Enforcement actions for the current case */
		actions: [],
		/** @type {object} LHS matrix configuration */
		lhsMatrix: DEFAULT_LHS_MATRIX,
		/** @type {boolean} Whether matrix has been loaded from settings */
		matrixLoaded: false,
		/** @type {boolean} Loading state */
		loading: false,
		/** @type {string|null} Error message */
		error: null,
	}),

	getters: {
		/**
		 * Get the current active enforcement action.
		 *
		 * @param {object} state Store state
		 * @return {object|null} Active action
		 */
		/**
		 * @param state
		 * @spec openspec/specs/vth-module/spec.md
		 */
		activeAction(state) {
			return state.actions.find((a) => a.status === 'opgelegd') || null
		},

		/**
		 * Calculate total verbeurd amount.
		 *
		 * @param {object} state Store state
		 * @return {number} Total verbeurd amount
		 */
		/**
		 * @param state
		 * @spec openspec/specs/vth-module/spec.md
		 */
		totalVerbeurd(state) {
			return state.actions
				.filter((a) => a.status === 'verbeurd')
				.reduce((sum, a) => sum + (a.dwangsomBedrag || 0), 0)
		},

		/**
		 * Get LHS matrix ernst levels.
		 *
		 * @param {object} state Store state
		 * @return {Array} Ernst levels
		 */
		/**
		 * @param state
		 * @spec openspec/specs/vth-module/spec.md
		 */
		ernstLevels(state) {
			return Object.keys(state.lhsMatrix)
		},

		/**
		 * Get LHS matrix gedrag levels.
		 *
		 * @param {object} state Store state
		 * @return {Array} Gedrag levels
		 */
		/**
		 * @param state
		 * @spec openspec/specs/vth-module/spec.md
		 */
		gedragLevels(state) {
			const first = Object.values(state.lhsMatrix)[0]
			return first ? Object.keys(first) : []
		},
	},

	actions: {
		/**
		 * Fetch enforcement actions for a case.
		 *
		 * @param {string} caseId UUID of the case
		 * @return {Promise<Array>} Actions
		 */
		/**
		 * @param caseId
		 * @spec openspec/specs/vth-module/spec.md
		 */
		async fetchActions(caseId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const response = await objectStore.fetchCollection(
					'handhavingsactie',
					{
						'_filters[case]': caseId,
						limit: 100,
					},
				)
				this.actions = response?.results || response || []
				return this.actions
			} catch (error) {
				this.error = error.message
				console.error('Error fetching enforcement actions:', error)
				return []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Look up the LHS matrix and return the suggested intervention.
		 *
		 * @param {string} ernst  Severity level (gering/aanzienlijk/ernstig)
		 * @param {string} gedrag Behavior type (goedwillend/onverschillig/calculerend/crimineel)
		 * @return {string|null} Suggested intervention
		 */
		/**
		 * @param ernst
		 * @param gedrag
		 * @spec openspec/specs/vth-module/spec.md
		 */
		lookupLhs(ernst, gedrag) {
			if (!this.lhsMatrix[ernst]) {
				return null
			}
			return this.lhsMatrix[ernst][gedrag] || null
		},

		/**
		 * Load the LHS matrix from app settings (IAppConfig).
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/specs/vth-module/spec.md */
		async loadLhsMatrix() {
			if (this.matrixLoaded) {
				return
			}
			try {
				const response = await fetch('/apps/procest/api/settings', {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})
				if (response.ok) {
					const data = await response.json()
					if (data.config?.lhsMatrix) {
						this.lhsMatrix =
							typeof data.config.lhsMatrix === 'string'
								? JSON.parse(data.config.lhsMatrix)
								: data.config.lhsMatrix
					}
				}
				this.matrixLoaded = true
			} catch (error) {
				console.error('Error loading LHS matrix:', error)
				// Keep default matrix
				this.matrixLoaded = true
			}
		},

		/**
		 * Save the LHS matrix to app settings.
		 *
		 * @param {object} matrix The matrix to save
		 * @return {Promise<boolean>} Success
		 */
		/**
		 * @param matrix
		 * @spec openspec/specs/vth-module/spec.md
		 */
		async saveLhsMatrix(matrix) {
			try {
				const response = await fetch('/apps/procest/api/settings', {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify({ lhsMatrix: JSON.stringify(matrix) }),
				})
				if (response.ok) {
					this.lhsMatrix = matrix
					return true
				}
				return false
			} catch (error) {
				console.error('Error saving LHS matrix:', error)
				return false
			}
		},

		/**
		 * Create an enforcement action.
		 *
		 * @param {object} actionData The action data
		 * @return {Promise<object|null>} Created action
		 */
		/**
		 * @param actionData
		 * @spec openspec/specs/vth-module/spec.md
		 */
		async createAction(actionData) {
			this.loading = true
			this.error = null
			try {
				// Auto-lookup intervention if not provided
				if (
					!actionData.interventie
					&& actionData.ernst
					&& actionData.gedrag
				) {
					actionData.interventie = this.lookupLhs(
						actionData.ernst,
						actionData.gedrag,
					)
				}

				const objectStore = useObjectStore()
				const saved = await objectStore.saveObject('handhavingsactie', {
					...actionData,
					status: actionData.status || 'opgelegd',
				})
				this.actions.push(saved)
				return saved
			} catch (error) {
				this.error = error.message
				console.error('Error creating enforcement action:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update enforcement action status.
		 *
		 * @param {string} actionId UUID of the action
		 * @param {string} newStatus New status (verbeurd/geeffectueerd/ingetrokken)
		 * @return {Promise<object|null>} Updated action
		 */
		/**
		 * @param actionId
		 * @param newStatus
		 * @spec openspec/specs/vth-module/spec.md
		 */
		async updateStatus(actionId, newStatus) {
			this.loading = true
			try {
				const action = this.actions.find((a) => a.id === actionId)
				if (!action) {
					return null
				}

				const objectStore = useObjectStore()
				const updated = await objectStore.saveObject('handhavingsactie', {
					...action,
					status: newStatus,
				})

				const index = this.actions.findIndex((a) => a.id === actionId)
				if (index >= 0) {
					this.actions.splice(index, 1, updated)
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
		 * Create a follow-up task when begunstigingstermijn expires.
		 *
		 * @param {string} caseId    UUID of the case
		 * @param {object} action    The enforcement action
		 * @return {Promise<object|null>} Created task
		 */
		/**
		 * @param caseId
		 * @param action
		 * @spec openspec/specs/vth-module/spec.md
		 */
		async createBegunstigingTask(caseId, action) {
			try {
				const objectStore = useObjectStore()
				return await objectStore.saveObject('task', {
					case: caseId,
					title: 'Hercontrole uitvoeren',
					description: `Begunstigingstermijn van ${action.begunstigingstermijn} dagen is verlopen. Voer hercontrole uit voor ${action.interventie}.`,
					status: 'open',
					relatedObject: action.id,
				})
			} catch (error) {
				console.error('Error creating begunstiging task:', error)
				return null
			}
		},
	},
})
