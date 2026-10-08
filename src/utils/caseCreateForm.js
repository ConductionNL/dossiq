/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one definition of the case create form, for the surfaces that configure
 * CnIndexPage from JS. The manifest-driven surfaces — the Dashboard and
 * MyWorkHome `new-case` header actions, and the Cases and Queue index pages —
 * declare the same shape in `manifest.json`, which cannot import this. Keep
 * this and those four in step; a field added here and nowhere else is a form
 * that asks a different question depending on which button opened it, which is
 * the drift `friendly-case-create-form` exists to prevent.
 */

/**
 * CnIndexPage props for a page whose Add button files a case.
 *
 * @return {object} Props to bind on CnIndexPage.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md
 */
export function caseCreateFormProps() {
	return {
		// Redundant against the whitelist below and kept anyway: status is the
		// OUTPUT of a lifecycle transition and priority is derived from the case
		// type's matrix, so neither may reach a form even if the whitelist is
		// later widened.
		excludeFields: [
			'status',
			'priority',
			'priorityOverride',
			'priorityOverrideReason',
		],
		includeFields: [
			'caseType',
			'title',
			'requester',
			'description',
			'assignee',
			'impact',
			'urgency',
			'confidentiality',
			'intakeChannel',
			'startDate',
			'plannedEndDate',
		],
		fieldOverrides: {
			description: { widget: 'textarea' },
			// Declared, not yet mounted: CnFormDialog resolves the widget from
			// the schema's own referenceType before it reads field.widget, so
			// until the library mounts `form-field` registry entries this is the
			// binding waiting for it (see registry.js InitiatorPicker).
			requester: { widget: 'InitiatorPicker', label: 'Requester' },
		},
		// Both stay editable, and a case type carrying a defaultAssignee still
		// wins through x-openregister-prefill: the type knows who handles it.
		createDefaults: { assignee: '@me', confidentiality: 'vertrouwelijk' },
		formSize: 'large',
		formColumns: 2,
		createSuccessRoute: 'CaseDetail',
		createSuccessMessage: 'Case created.',
	}
}
