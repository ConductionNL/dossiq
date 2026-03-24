import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { useBezwaarStore } from './modules/bezwaar.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	const config = await settingsStore.fetchSettings()

	if (config) {
		if (config.register && config.case_schema) {
			objectStore.registerObjectType('case', config.case_schema, config.register)
		}
		if (config.register && config.task_schema) {
			objectStore.registerObjectType('task', config.task_schema, config.register)
		}
		if (config.register && config.status_schema) {
			objectStore.registerObjectType('status', config.status_schema, config.register)
		}
		if (config.register && config.role_schema) {
			objectStore.registerObjectType('role', config.role_schema, config.register)
		}
		if (config.register && config.result_schema) {
			objectStore.registerObjectType('result', config.result_schema, config.register)
		}
		if (config.register && config.decision_schema) {
			objectStore.registerObjectType('decision', config.decision_schema, config.register)
		}
		if (config.register && config.case_type_schema) {
			objectStore.registerObjectType('caseType', config.case_type_schema, config.register)
		}
		if (config.register && config.status_type_schema) {
			objectStore.registerObjectType('statusType', config.status_type_schema, config.register)
		}
		if (config.register && config.result_type_schema) {
			objectStore.registerObjectType('resultType', config.result_type_schema, config.register)
		}
		if (config.register && config.role_type_schema) {
			objectStore.registerObjectType('roleType', config.role_type_schema, config.register)
		}
		if (config.register && config.property_definition_schema) {
			objectStore.registerObjectType('propertyDefinition', config.property_definition_schema, config.register)
		}
		if (config.register && config.document_type_schema) {
			objectStore.registerObjectType('documentType', config.document_type_schema, config.register)
		}
		if (config.register && config.decision_type_schema) {
			objectStore.registerObjectType('decisionType', config.decision_type_schema, config.register)
		}
		if (config.register && config.map_layer_schema) {
			objectStore.registerObjectType('mapLayer', config.map_layer_schema, config.register)
		}
		if (config.register && config.objection_schema) {
			objectStore.registerObjectType('objection', config.objection_schema, config.register)
		}
		if (config.register && config.hearing_session_schema) {
			objectStore.registerObjectType('hearingSession', config.hearing_session_schema, config.register)
		}
		if (config.register && config.advisory_report_schema) {
			objectStore.registerObjectType('advisoryReport', config.advisory_report_schema, config.register)
		}
		if (config.register && config.appeal_decision_schema) {
			objectStore.registerObjectType('appealDecision', config.appeal_decision_schema, config.register)
		}
		if (config.register && config.workflow_template_schema) {
			objectStore.registerObjectType('workflowTemplate', config.workflow_template_schema, config.register)
		}
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore, useBezwaarStore }
