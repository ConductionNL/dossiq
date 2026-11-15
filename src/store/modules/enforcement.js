/**
 * Enforcement store module for Dossiq VTH.
 *
 * Manages enforcement actions (handhavingsacties), LHS matrix lookup,
 * dwangsom tracking, and begunstigingstermijn management.
 */
import { defineStore } from 'pinia'
import { useEngineTaskStore } from './engineTask.js'
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

/**
 * The LHS matrix configured in the app settings, or undefined when none is
 * configured or the settings cannot be read.
 *
 * @return {Promise<object|null|undefined>} The configured matrix.
 */
async function fetchConfiguredMatrix() {
	const response = await fetch('/apps/dossiq/api/settings', {
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
			'OCS-APIREQUEST': 'true',
		},
	})
	if (!response.ok) {
		return undefined
	}
	const data = await response.json()
	return storedMatrix(data.config?.lhsMatrix)
}

/**
 * A stored LHS matrix, parsed when it was stored as text; undefined when
 * nothing is stored.
 *
 * @param {object|string|undefined} stored The stored matrix.
 * @return {object|null|undefined} The matrix.
 */
function storedMatrix(stored) {
	if (!stored) {
		return undefined
	}
	return typeof stored === 'string' ? JSON.parse(stored) : stored
}

/**
 * Save a new enforcement action, with its intervention looked up when not
 * given, and keep it in the store.
 *
 * @param {object} store The enforcement store.
 * @param {object} actionData The action data.
 * @return {Promise<object>} The saved action.
 */
async function saveNewAction(store, actionData) {
	fillIntervention(store, actionData)

	const objectStore = useObjectStore()
	const saved = await objectStore.saveObject('handhavingsactie', {
		...actionData,
		status: actionData.status || 'opgelegd',
	})
	store.actions.push(saved)
	return saved
}

/**
 * Look the intervention up in the LHS matrix when the action does not name
 * one but does name its severity and behaviour.
 *
 * @param {object} store The enforcement store.
 * @param {object} actionData The action data, completed in place.
 */
function fillIntervention(store, actionData) {
	if (
		!actionData.intervention
		&& actionData.severity
		&& actionData.behaviour
	) {
		actionData.intervention = store.lookupLhs(
			actionData.severity,
			actionData.behaviour,
		)
	}
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
		 * @spec openspec/specs/vth-module/spec.md
		 */
		totalVerbeurd(state) {
			return state.actions
				.filter((a) => a.status === 'verbeurd')
				.reduce((sum, a) => sum + (a.penaltyPaymentAmount || 0), 0)
		},

		/**
		 * Get LHS matrix ernst levels.
		 *
		 * @param {object} state Store state
		 * @return {Array} Ernst levels
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
						case: caseId,
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
				const matrix = await fetchConfiguredMatrix()
				if (matrix !== undefined) {
					this.lhsMatrix = matrix
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
		 * @spec openspec/specs/vth-module/spec.md
		 */
		async saveLhsMatrix(matrix) {
			try {
				const response = await fetch('/apps/dossiq/api/settings', {
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
		 * @spec openspec/specs/vth-module/spec.md
		 */
		async createAction(actionData) {
			this.loading = true
			this.error = null
			try {
				return await saveNewAction(this, actionData)
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
		 * @spec openspec/specs/vth-module/spec.md
		 */
		async createBegunstigingTask(caseId, action) {
			try {
				return await useEngineTaskStore().create({
					case: caseId,
					title: 'Hercontrole uitvoeren',
					description: `Begunstigingstermijn van ${action.compliance_period} dagen is verlopen. Voer hercontrole uit voor ${action.intervention}.`,
					status: 'available',
					relatedObject: action.id,
				})
			} catch (error) {
				console.error('Error creating begunstiging task:', error)
				return null
			}
		},
	},
})
