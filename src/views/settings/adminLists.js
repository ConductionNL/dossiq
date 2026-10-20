// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * The lists on the admin page, declared as CnIndexPage props over dossiq's own
 * endpoints, kept apart from AdminRoot so they can be read and tested without
 * mounting the page. Each endpoint keeps its own role check.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

/**
 * The coordinator's substitution console: every substitution, searched by
 * handler, with register-on-behalf and bulk reassignment in the header, the
 * actions done under one and its revocation on each row. The coordinator role
 * is enforced by SubstitutionController.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @param {(key: string, props?: object) => void} openModal The page's modal host.
 * @return {object} The CnIndexPage props.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export function substitutionIndex(t, openModal) {
	return {
		title: t('dossiq', 'Substitutions & reassignment'),
		showTitle: true,
		entitySource: 'endpoint',
		sourceConfig: {
			url: '/apps/dossiq/api/substitutions',
			rowsPath: 'results',
			searchParam: 'handler',
		},
		inlineSearch: true,
		searchPlaceholder: t('dossiq', 'Filter by handler…'),
		emptyText: t('dossiq', 'No substitutions'),
		showAdd: false,
		showViewAction: false,
		showEditAction: false,
		showCopyAction: false,
		showDeleteAction: false,
		allowSavedViews: false,
		columns: [
			{ key: 'absentee', label: t('dossiq', 'Absentee') },
			{ key: 'substitute', label: t('dossiq', 'Substitute') },
			{ key: 'startDate', label: t('dossiq', 'From') },
			{ key: 'endDate', label: t('dossiq', 'Until') },
			{ key: 'scope', label: t('dossiq', 'Scope') },
			{
				key: 'status',
				label: t('dossiq', 'Status'),
				widget: 'badge',
				enum: ['active', 'ended', 'revoked'],
				enumLabels: { active: t('dossiq', 'Active'), ended: t('dossiq', 'Ended'), revoked: t('dossiq', 'Revoked') },
				colorMap: { active: 'success', ended: 'default', revoked: 'default' },
			},
			{ key: 'actions', label: '', widget: 'actions', widgetProps: { actions: ['actions-done', 'revoke'] } },
		],
		headerButtons: [
			{ action: 'bulk-reassign', label: t('dossiq', 'Bulk reassign') },
			{ action: 'register-substitution', label: t('dossiq', 'Register for handler'), variant: 'primary' },
		],
		headerActions: [
			{ id: 'bulk-reassign', label: t('dossiq', 'Bulk reassign'), handler: () => openModal('BulkReassignModal') },
			{ id: 'register-substitution', label: t('dossiq', 'Register for handler'), handler: () => openModal('SubstitutionFormModal', { allowCoordinator: true }) },
		],
		actions: [
			{
				id: 'actions-done',
				label: t('dossiq', 'Actions'),
				variant: 'tertiary',
				type: 'open-modal',
				target: 'SubstitutionActionsDialog',
			},
			{
				id: 'revoke',
				label: t('dossiq', 'Revoke'),
				variant: 'tertiary',
				type: 'open-modal',
				target: 'CnActionDialog',
				visibleWhen: { field: 'status', op: 'eq', value: 'active' },
				props: {
					config: {
						title: t('dossiq', 'Revoke this substitution?'),
						summary: { title: '{absentee}', meta: '{substitute}', icon: 'AccountSwitch' },
						doneText: t('dossiq', 'The substitution is revoked.'),
						confirm: {
							label: t('dossiq', 'Revoke'),
							request: { method: 'POST', url: '/apps/dossiq/api/substitutions/@objectId/revoke' },
						},
					},
				},
			},
		],
	}
}

/**
 * The StUF message log: every outbound and inbound StUF call with its status,
 * searched by endpoint, narrowed by status and by message type, each envelope
 * inspectable, and the shown rows downloadable as CSV. Admin only on the
 * server (StufController).
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {object} The CnIndexPage props.
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
 */
export function stufAuditIndex(t) {
	const statuses = ['verzonden', 'bevestigd', 'fout', 'wacht_op_retry']
	const kinds = ['Lk01', 'Lk02', 'Lk03', 'Bv01', 'Lv01', 'La01', 'Du01', 'Fo02']
	return {
		// Not shown (showTitle false); it names the CSV, stuf-audit-log.csv.
		title: 'StUF audit log',
		showTitle: false,
		entitySource: 'endpoint',
		sourceConfig: {
			url: '/apps/dossiq/api/stuf/messages',
			rowsPath: 'items',
			searchParam: 'endpointId',
			searchFields: { messageKind: { param: 'messageKind', single: true } },
			limit: 100,
		},
		inlineSearch: true,
		searchPlaceholder: t('dossiq', 'Endpoint ID'),
		emptyText: t('dossiq', 'No StUF messages match the filters.'),
		showAdd: false,
		showViewAction: false,
		showEditAction: false,
		showCopyAction: false,
		showDeleteAction: false,
		allowSavedViews: false,
		quickFilters: [
			{ label: t('dossiq', 'All'), filter: {}, default: true },
			...statuses.map((status) => ({ label: status, filter: { status } })),
		],
		filterStrip: {
			field: 'messageKind',
			label: t('dossiq', 'Message type'),
			options: kinds.map((kind) => ({ value: kind, label: kind })),
		},
		columns: [
			{ key: 'sentOn', label: t('dossiq', 'Sent at') },
			{ key: 'direction', label: t('dossiq', 'Direction') },
			{ key: 'messageKind', label: t('dossiq', 'Message') },
			{ key: 'role', label: t('dossiq', 'Function') },
			{
				key: 'status',
				label: t('dossiq', 'Status'),
				widget: 'badge',
				colorMap: { verzonden: 'primary', bevestigd: 'success', fout: 'error', wacht_op_retry: 'warning' },
			},
			{ key: 'httpStatus', label: t('dossiq', 'HTTP') },
			{ key: 'durationMs', label: t('dossiq', 'Duration (ms)') },
			{ key: 'referenceNumber', label: t('dossiq', 'Reference') },
			{ key: 'caseIdentification', label: t('dossiq', 'Case') },
			{ key: 'actions', label: '', widget: 'actions', widgetProps: { actions: ['inspect'] } },
		],
		headerButtons: [
			{ action: 'refresh', label: t('dossiq', 'Reload') },
			{ action: 'export', label: t('dossiq', 'Export CSV') },
		],
		actions: [
			{ id: 'inspect', label: t('dossiq', 'Inspect'), variant: 'tertiary', type: 'open-modal', target: 'StufEnvelopeDialog' },
		],
	}
}
