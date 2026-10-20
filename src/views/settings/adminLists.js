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
