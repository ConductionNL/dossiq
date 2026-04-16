import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { useBezwaarStore } from './modules/bezwaar.js'

/**
 * Data-driven schema registration configuration.
 * Maps object type names to their corresponding config keys for OpenRegister.
 *
 * @spec openspec/changes/openregister-integration/tasks.md#task-1
 */
const SCHEMA_REGISTRATIONS = [
	// Configuration schemas
	{ type: 'caseType', configKey: 'case_type_schema' },
	{ type: 'statusType', configKey: 'status_type_schema' },
	{ type: 'resultType', configKey: 'result_type_schema' },
	{ type: 'roleType', configKey: 'role_type_schema' },
	{ type: 'propertyDefinition', configKey: 'property_definition_schema' },
	{ type: 'documentType', configKey: 'document_type_schema' },
	{ type: 'decisionType', configKey: 'decision_type_schema' },

	// Instance schemas
	{ type: 'case', configKey: 'case_schema' },
	{ type: 'task', configKey: 'task_schema' },
	{ type: 'role', configKey: 'role_schema' },
	{ type: 'result', configKey: 'result_schema' },
	{ type: 'decision', configKey: 'decision_schema' },

	// ZGW support schemas
	{ type: 'catalogus', configKey: 'catalogus_schema' },
	{ type: 'status', configKey: 'status_schema' },
	{ type: 'statusRecord', configKey: 'status_record_schema' },
	{ type: 'zaaktypeInformatieobjecttype', configKey: 'zaaktype_informatieobjecttype_schema' },
	{ type: 'caseProperty', configKey: 'case_property_schema' },
	{ type: 'caseDocument', configKey: 'case_document_schema' },
	{ type: 'caseObject', configKey: 'case_object_schema' },
	{ type: 'customerContact', configKey: 'customer_contact_schema' },
	{ type: 'decisionDocument', configKey: 'decision_document_schema' },
	{ type: 'dispatch', configKey: 'dispatch_schema' },
	{ type: 'document', configKey: 'document_schema' },
	{ type: 'documentLink', configKey: 'document_link_schema' },
	{ type: 'usageRights', configKey: 'usage_rights_schema' },
	{ type: 'kanaal', configKey: 'kanaal_schema' },
	{ type: 'abonnement', configKey: 'abonnement_schema' },

	// Additional schemas
	{ type: 'mapLayer', configKey: 'map_layer_schema' },
	{ type: 'objection', configKey: 'objection_schema' },
	{ type: 'hearingSession', configKey: 'hearing_session_schema' },
	{ type: 'advisoryReport', configKey: 'advisory_report_schema' },
	{ type: 'appealDecision', configKey: 'appeal_decision_schema' },
	{ type: 'workflowTemplate', configKey: 'workflow_template_schema' },
]

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	const config = await settingsStore.fetchSettings()

	if (config) {
		let registeredCount = 0

		// Register all configured schemas
		for (const schema of SCHEMA_REGISTRATIONS) {
			if (config.register && config[schema.configKey]) {
				objectStore.registerObjectType(schema.type, config[schema.configKey], config.register)
				registeredCount++
			}
		}

		// Debug log
		if (process.env.NODE_ENV === 'development') {
			console.debug(`[Procest] Registered ${registeredCount} schemas`)
		}
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore, useBezwaarStore }
