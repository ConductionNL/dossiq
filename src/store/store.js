import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

/**
 * The object types registered at start-up, in registration order.
 *
 * `type` is the store's name for it, `key` the app-config key carrying its
 * schema id, and `fallback` the schema slug used when that key is empty.
 * A type without a fallback is only registered when its key is set.
 */
const OBJECT_TYPES = [
	// inspection-checklists-onto-task 4.1: the one checklist template
	// schema, read by the inspection panel. Registered by slug; the panel's
	// read threw "not registered" before this.
	{ type: 'inspectionChecklistTemplate', key: null, fallback: 'inspectionChecklistTemplate' },
	{ type: 'case', key: 'case_schema', fallback: null },
	{ type: 'status', key: 'status_schema', fallback: null },
	{ type: 'role', key: 'role_schema', fallback: null },
	{ type: 'result', key: 'result_schema', fallback: null },
	{ type: 'decision', key: 'decision_schema', fallback: null },
	// caseType / statusType power the Workflow Board kanban and the
	// Doorlooptijd analytics view. Their numeric schema id is not always
	// seeded into the app-config (case_type_schema/status_type_schema can
	// be blank on a fresh OR register), which previously left these types
	// unregistered and produced "Object type is not registered" console
	// errors plus empty kanban columns. The OR object API resolves a
	// schema by its canonical slug as well as its numeric id, so fall back
	// to the schema slug ('caseType' / 'statusType') when the config value
	// is empty. This keeps the board/analytics functional regardless of
	// whether the register config carries the numeric ids.
	{ type: 'caseType', key: 'case_type_schema', fallback: 'caseType' },
	{ type: 'statusType', key: 'status_type_schema', fallback: 'statusType' },
	// Same slug fallback, same reason as caseType/statusType above, plus one
	// of its own: the case page asks for a case type's result types to know
	// what to offer when a transition CLOSES the case. Left unregistered,
	// that read throws and the closing dialog offers no result at all — a
	// case type with results configured would close without one, which is
	// exactly what REQ-STE-12 forbids.
	{ type: 'resultType', key: 'result_type_schema', fallback: 'resultType' },
	{ type: 'roleType', key: 'role_type_schema', fallback: null },
	{ type: 'propertyDefinition', key: 'property_definition_schema', fallback: null },
	{ type: 'documentType', key: 'document_type_schema', fallback: null },
	// A case type rule names its message by template id; the rule rows read the names through this type.
	{ type: 'emailTemplate', key: 'email_template_schema', fallback: 'emailTemplate' },
	{ type: 'decisionType', key: 'decision_type_schema', fallback: null },
	{ type: 'mapLayer', key: 'map_layer_schema', fallback: null },
	{ type: 'objection', key: 'objection_schema', fallback: null },
	{ type: 'hearingSession', key: 'hearing_session_schema', fallback: null },
	{ type: 'advisoryReport', key: 'advisory_report_schema', fallback: null },
	{ type: 'appealDecision', key: 'appeal_decision_schema', fallback: null },
	{ type: 'workflowTemplate', key: 'workflow_template_schema', fallback: null },
	// Case-document relation records (case detail Documents tab +
	// DocumentChecklist). Slug fallback like caseType/statusType above.
	{ type: 'caseDocument', key: 'case_document_schema', fallback: 'caseDocument' },
	// The two party register sets behind the requester (brp-kvk register
	// sets). They were the only types the app READ without registering:
	// `_getTypeConfig` THROWS on an unregistered type, the picker caught
	// it in its search try/catch, and every person and company search
	// answered "no matching records in the seeded register set" — which
	// is exactly what an empty register looks like. Slug fallback like
	// caseDocument above; both are seeded by the 25-brp-kvk fragment.
	{ type: 'brpPerson', key: 'brp_person_schema', fallback: 'brpPerson' },
	{ type: 'kvkCompany', key: 'kvk_company_schema', fallback: 'kvkCompany' },
]

/** @spec openspec/specs/openregister-integration/spec.md */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	const config = await settingsStore.fetchSettings()

	if (config?.register) {
		registerObjectTypes(objectStore, config)
	}

	return { settingsStore, objectStore }
}

/**
 * Register every start-up object type whose schema the config names, or
 * that has a slug fallback, against the config's register.
 *
 * @param {object} objectStore The object store.
 * @param {object} config The app config, carrying a register.
 */
function registerObjectTypes(objectStore, config) {
	for (const entry of OBJECT_TYPES) {
		const schema = (entry.key && config[entry.key]) || entry.fallback
		if (schema) {
			objectStore.registerObjectType(entry.type, schema, config.register)
		}
	}
}

export { useObjectStore, useSettingsStore }
