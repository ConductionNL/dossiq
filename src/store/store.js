import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

/**
 * Schema slug to config key mapping.
 *
 * Maps the logical object type name used in the frontend
 * to the settings config key that holds the schema ID.
 */
const SCHEMA_REGISTRATIONS = [
	// Instance schemas.
	{ type: 'case', configKey: 'case_schema' },
	{ type: 'task', configKey: 'task_schema' },
	{ type: 'status', configKey: 'status_schema' },
	{ type: 'statusRecord', configKey: 'status_record_schema' },
	{ type: 'role', configKey: 'role_schema' },
	{ type: 'result', configKey: 'result_schema' },
	{ type: 'decision', configKey: 'decision_schema' },
	// Configuration schemas.
	{ type: 'caseType', configKey: 'case_type_schema' },
	{ type: 'statusType', configKey: 'status_type_schema' },
	{ type: 'resultType', configKey: 'result_type_schema' },
	{ type: 'roleType', configKey: 'role_type_schema' },
	{ type: 'propertyDefinition', configKey: 'property_definition_schema' },
	{ type: 'documentType', configKey: 'document_type_schema' },
	{ type: 'decisionType', configKey: 'decision_type_schema' },
	// ZGW support schemas.
	{ type: 'catalogus', configKey: 'catalogus_schema' },
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
]

/**
 * Initialize Pinia stores with settings and register all OpenRegister object types.
 *
 * Fetches app settings, checks OpenRegister availability, and registers
 * all schema-to-object-type mappings in the object store.
 *
 * @return {Promise<{ settingsStore, objectStore, openRegisters: boolean }>}
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	const config = await settingsStore.fetchSettings()

	if (config && config.register) {
		let registered = 0
		for (const { type, configKey } of SCHEMA_REGISTRATIONS) {
			const schemaId = config[configKey]
			if (schemaId) {
				objectStore.registerObjectType(type, schemaId, config.register)
				registered++
			}
		}

		console.debug(`[Procest] Registered ${registered}/${SCHEMA_REGISTRATIONS.length} object types`)
	}

	return {
		settingsStore,
		objectStore,
		openRegisters: settingsStore.openRegisters,
	}
}

export { useObjectStore, useSettingsStore }
