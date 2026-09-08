import { useBezwaarStore } from './modules/bezwaar.js'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

/** @spec openspec/specs/openregister-integration/spec.md */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	const config = await settingsStore.fetchSettings()

	if (config) {
		if (config.register && config.case_schema) {
			objectStore.registerObjectType(
				'case',
				config.case_schema,
				config.register,
			)
		}
		if (config.register && config.task_schema) {
			objectStore.registerObjectType(
				'caseTask',
				config.task_schema,
				config.register,
			)
		}
		if (config.register && config.status_schema) {
			objectStore.registerObjectType(
				'status',
				config.status_schema,
				config.register,
			)
		}
		if (config.register && config.role_schema) {
			objectStore.registerObjectType(
				'role',
				config.role_schema,
				config.register,
			)
		}
		if (config.register && config.result_schema) {
			objectStore.registerObjectType(
				'result',
				config.result_schema,
				config.register,
			)
		}
		if (config.register && config.decision_schema) {
			objectStore.registerObjectType(
				'decision',
				config.decision_schema,
				config.register,
			)
		}
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
		if (config.register) {
			objectStore.registerObjectType(
				'caseType',
				config.case_type_schema || 'caseType',
				config.register,
			)
		}
		if (config.register) {
			objectStore.registerObjectType(
				'statusType',
				config.status_type_schema || 'statusType',
				config.register,
			)
		}
		// Same slug fallback, same reason as caseType/statusType above, plus one
		// of its own: the case page asks for a case type's result types to know
		// what to offer when a transition CLOSES the case. Left unregistered,
		// that read throws and the closing dialog offers no result at all — a
		// case type with results configured would close without one, which is
		// exactly what REQ-STE-12 forbids.
		if (config.register) {
			objectStore.registerObjectType(
				'resultType',
				config.result_type_schema || 'resultType',
				config.register,
			)
		}
		if (config.register && config.role_type_schema) {
			objectStore.registerObjectType(
				'roleType',
				config.role_type_schema,
				config.register,
			)
		}
		if (config.register && config.property_definition_schema) {
			objectStore.registerObjectType(
				'propertyDefinition',
				config.property_definition_schema,
				config.register,
			)
		}
		if (config.register && config.document_type_schema) {
			objectStore.registerObjectType(
				'documentType',
				config.document_type_schema,
				config.register,
			)
		}
		if (config.register && config.decision_type_schema) {
			objectStore.registerObjectType(
				'decisionType',
				config.decision_type_schema,
				config.register,
			)
		}
		if (config.register && config.map_layer_schema) {
			objectStore.registerObjectType(
				'mapLayer',
				config.map_layer_schema,
				config.register,
			)
		}
		if (config.register && config.objection_schema) {
			objectStore.registerObjectType(
				'objection',
				config.objection_schema,
				config.register,
			)
		}
		if (config.register && config.hearing_session_schema) {
			objectStore.registerObjectType(
				'hearingSession',
				config.hearing_session_schema,
				config.register,
			)
		}
		if (config.register && config.advisory_report_schema) {
			objectStore.registerObjectType(
				'advisoryReport',
				config.advisory_report_schema,
				config.register,
			)
		}
		if (config.register && config.appeal_decision_schema) {
			objectStore.registerObjectType(
				'appealDecision',
				config.appeal_decision_schema,
				config.register,
			)
		}
		if (config.register && config.workflow_template_schema) {
			objectStore.registerObjectType(
				'workflowTemplate',
				config.workflow_template_schema,
				config.register,
			)
		}
		// Case-document relation records (case detail Documents tab +
		// DocumentChecklist). Slug fallback like caseType/statusType above.
		if (config.register) {
			objectStore.registerObjectType(
				'caseDocument',
				config.case_document_schema || 'caseDocument',
				config.register,
			)
		}
		// The two party register sets behind the requester (brp-kvk register
		// sets). They were the only types the app READ without registering:
		// `_getTypeConfig` THROWS on an unregistered type, the picker caught
		// it in its search try/catch, and every person and company search
		// answered "no matching records in the seeded register set" — which
		// is exactly what an empty register looks like. Slug fallback like
		// caseDocument above; both are seeded by the 25-brp-kvk fragment.
		if (config.register) {
			objectStore.registerObjectType(
				'brpPerson',
				config.brp_person_schema || 'brpPerson',
				config.register,
			)
			objectStore.registerObjectType(
				'kvkCompany',
				config.kvk_company_schema || 'kvkCompany',
				config.register,
			)
		}
	}

	return { settingsStore, objectStore }
}

export { useBezwaarStore, useObjectStore, useSettingsStore }
