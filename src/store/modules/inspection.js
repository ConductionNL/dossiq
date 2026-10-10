/**
 * Inspection store module for Dossiq VTH.
 *
 * Manages inspection checklists, inspection reports, photo uploads,
 * and follow-up task creation for VTH supervision cases.
 */
import { defineStore } from 'pinia'
import { useEngineTaskStore } from './engineTask.js'
import { useObjectStore } from './object.js'

/**
 * A template with its section items as one flat, ordered `items` list.
 *
 * @param {object} template An `inspectionChecklistTemplate` object
 * @return {object} The template, with `items`
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */
export function flattenTemplate(template) {
	const sections = Array.isArray(template?.sections) ? template.sections : []
	const items = sections.flatMap((section) =>
		Array.isArray(section?.items) ? section.items : [],
	)
	return { ...template, items }
}

export const useInspectionStore = defineStore('inspection', {
	state: () => ({
		/** @type {Array} Checklists for the current case type */
		checklists: [],
		/** @type {object|null} Currently selected checklist */
		currentChecklist: null,
		/** @type {Array} Inspection reports for the current case */
		reports: [],
		/** @type {boolean} Loading state */
		loading: false,
		/** @type {string|null} Error message */
		error: null,
	}),

	getters: {
		/**
		 * Get active checklists (not archived).
		 *
		 * @param {object} state Store state
		 * @return {Array} Active checklists
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		activeChecklists(state) {
			return state.checklists.filter((c) => c.status === 'active')
		},

		/**
		 * Get completed reports count.
		 *
		 * @param {object} state Store state
		 * @return {number} Number of completed reports
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		completedReportsCount(state) {
			return state.reports.length
		},

		/**
		 * Get reports with non-conformities.
		 *
		 * @param {object} state Store state
		 * @return {Array} Reports with failed items
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		nonConformReports(state) {
			return state.reports.filter(
				(r) => r.result === 'non_conform' || r.result === 'partly_conform',
			)
		},
	},

	actions: {
		/**
		 * Fetch the checklist templates for a case type.
		 *
		 * Templates are `inspectionChecklistTemplate` objects, the one template
		 * schema (inspection-checklists-onto-task 4.1). Their items sit in
		 * sections; the panel reads one flat `items` list, so each template is
		 * flattened here.
		 *
		 * @param {string} caseTypeId UUID of the case type
		 * @return {Promise<Array>} Checklists
		 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
		 */
		async fetchChecklists(caseTypeId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const response = await objectStore.fetchCollection(
					'inspectionChecklistTemplate',
					{
						caseType: caseTypeId,
						limit: 100,
					},
				)
				const templates = response?.results || response || []
				this.checklists = templates.map(flattenTemplate)
				return this.checklists
			} catch (error) {
				this.error = error.message
				console.error('Error fetching checklists:', error)
				return []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch inspection reports for a case.
		 *
		 * @param {string} caseId UUID of the case
		 * @return {Promise<Array>} Reports
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async fetchReports(caseId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const response = await objectStore.fetchCollection(
					'inspectieRapport',
					{
						case: caseId,
						limit: 100,
					},
				)
				this.reports = response?.results || response || []
				return this.reports
			} catch (error) {
				this.error = error.message
				console.error('Error fetching reports:', error)
				return []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create an inspection report with auto-calculated result.
		 *
		 * @param {object} reportData Report data with items array
		 * @return {Promise<object|null>} Created report
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async createReport(reportData) {
			this.loading = true
			this.error = null
			try {
				const items = reportData.items || []
				const failedItems = items.filter(
					(item) => item.result === 'fail',
				).length
				const nvtItems = items.filter((item) => item.result === 'nvt').length
				const totalItems = items.length

				// Auto-calculate overall result
				let result = 'conform'
				if (failedItems > 0 && failedItems < totalItems - nvtItems) {
					result = 'partly_conform'
				} else if (failedItems > 0) {
					result = 'non_conform'
				}

				const report = {
					...reportData,
					result,
					failedItems,
					followUpRequired: failedItems > 0,
					inspectionDate:
						reportData.inspectionDate || new Date().toISOString(),
				}

				const objectStore = useObjectStore()
				const saved = await objectStore.saveObject(
					'inspectieRapport',
					report,
				)
				this.reports.push(saved)

				// Create follow-up task if non-conformities found
				if (failedItems > 0) {
					await this.createFollowUpTask(
						reportData.case,
						failedItems,
						saved.id,
					)
				}

				return saved
			} catch (error) {
				this.error = error.message
				console.error('Error creating report:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Upload a photo for an inspection item.
		 *
		 * Uses the @conduction/nextcloud-vue filesPlugin (`uploadFiles`),
		 * which expects FormData and the parent object's registered type.
		 * Returns the first uploaded file's ID, mirroring the legacy contract.
		 *
		 * @param {string} caseId  UUID of the parent case
		 * @param {File}   file    The photo file
		 * @return {Promise<string|null>} Nextcloud file ID
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async uploadPhoto(caseId, file) {
			try {
				const objectStore = useObjectStore()
				const formData = new FormData()
				formData.append('file', file)
				const result = await objectStore.uploadFiles(
					'case',
					caseId,
					formData,
				)
				const uploaded = result?.results?.[0] || result?.[0] || result
				return uploaded?.id || null
			} catch (error) {
				console.error('Error uploading inspection photo:', error)
				return null
			}
		},

		/**
		 * Create a follow-up task when non-conformities are found.
		 *
		 * @param {string} caseId      UUID of the case
		 * @param {number} failedCount Number of failed items
		 * @param {string} reportId    UUID of the inspection report
		 * @return {Promise<object|null>} Created task
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async createFollowUpTask(caseId, failedCount, reportId) {
			try {
				return await useEngineTaskStore().create({
					case: caseId,
					title: `Opvolging vereist: ${failedCount} afwijkingen geconstateerd`,
					description: `Inspectierapport bevat ${failedCount} niet-conforme punten. Beoordeel de afwijkingen en plan opvolging.`,
					status: 'available',
					relatedObject: reportId,
				})
			} catch (error) {
				console.error('Error creating follow-up task:', error)
				return null
			}
		},
	},
})
