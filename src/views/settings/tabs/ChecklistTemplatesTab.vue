<!--
  Checklist templates, for an administrator: the list, and a form dialog whose
  items are an editable table of sub-objects.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  @spec openspec/changes/vth-module/tasks.md#task-5
-->
<template>
	<div class="checklists-tab">
		<div v-if="!hideAddButton" class="checklists-tab__header">
			<NcButton variant="primary" @click="openEditor(null)">
				{{ t('dossiq', 'New checklist') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-if="!loading && checklists.length === 0"
			:name="t('dossiq', 'No checklists')"
			:description="
				t('dossiq', 'Create an inspection checklist to get started.')
			">
			<template #icon>
				<NcIconSvgWrapper :svg="clipboardIcon" />
			</template>
		</NcEmptyContent>

		<!-- The library's list rows (board DqVthInstellingen): the name over its
		     version and size, the state as a status pill, Edit and Delete in the
		     row's menu. -->
		<div v-if="!loading" class="checklists-tab__list">
			<CnObjectRow
				v-for="row in rows"
				:key="row.id"
				class="checklists-tab__item"
				:object="row"
				:config="rowConfig">
				<template #actions>
					<CnRowActions
						:actions="rowActions"
						:row="row.checklist"
						:rowLabel="row.name" />
				</template>
			</CnObjectRow>
		</div>

		<CnFormDialog
			v-if="editing"
			ref="formDialog"
			:schema="templateSchema"
			:item="editingChecklist.id ? editingChecklist : null"
			:initialData="editingChecklist.id ? {} : editingChecklist"
			:dialogTitle="editingChecklist.id ? t('dossiq', 'Edit checklist') : t('dossiq', 'New checklist')"
			size="large"
			:feedback="false"
			:recoverDraft="false"
			@confirm="saveChecklist"
			@close="closeEditor" />

		<CnConfirmDialog
			v-if="showDeleteConfirm"
			ref="deleteConfirmDialog"
			:dialogTitle="t('dossiq', 'Delete checklist')"
			:message="
				t('dossiq', 'Delete checklist “{name}”?', {
					name: pendingDeleteChecklist && pendingDeleteChecklist.name,
				})
			"
			variant="error"
			:confirmLabel="t('dossiq', 'Delete')"
			@confirm="onConfirmDelete"
			@close="showDeleteConfirm = false" />
	</div>
</template>

<script>
import { CnConfirmDialog, CnFormDialog, CnObjectRow, CnRowActions } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcIconSvgWrapper,
	NcLoadingIcon,
} from '@nextcloud/vue'

/**
 * Checklist CRUD for the admin surface goes through dossiq's OWN controller.
 *
 * `ChecklistTemplateController` already serves exactly the four verbs this tab
 * uses — `GET/POST /api/checklists` and `PUT/DELETE /api/checklists/{id}`
 * (appinfo/routes.php) — and every one of them is guarded with
 * `#[AuthorizedAdminSetting(settings: AdminSettings::class)]`, which is the right
 * posture for a control that lives in the admin settings page. It also routes
 * through `InspectionChecklistService`, which owns checklist versioning and
 * `caseTypeRef` filtering.
 *
 * The old URL, `/apps/dossiq/api/objects/inspectionChecklist`, matched NO route:
 * dossiq's auto-exposed `/api/objects/<register>/<schema>` endpoints were deleted
 * (appinfo/routes.php, "only engine routes remain"). Nextcloud answers an
 * unmatched app URL with its own HTML page under **HTTP 200**, so axios never
 * threw and the body was a 45,031-character string — see `asChecklistArray`.
 *
 * Addressing OpenRegister's generic object route instead would also have worked
 * mechanically, and was this fix's first attempt. It is the wrong choice: it
 * bypasses dossiq's admin-setting authorization, bypasses the service that owns
 * versioning, and adds a second write path for a resource that already has one.
 */
const COLLECTION_URL = '/apps/dossiq/api/checklists'

/**
 * Coerce an API response into the checklist array the template iterates.
 *
 * This guard is load-bearing, not defensive padding. `v-for` over a STRING
 * iterates one item per character, so assigning an unvalidated response body
 * straight to `checklists` rendered one table row — two NcButtons — per
 * character of whatever came back. A 45,031-byte HTML error page produced
 * 45,031 rows, 90,122 buttons and a 50 MB DOM on which Playwright's
 * accessible-name computation never terminated (procest#784).
 *
 * @param {unknown} body Parsed response body, of any shape.
 * @return {Array} The checklist rows, or an empty array when the body is not
 *                 a recognised collection shape.
 */
function asChecklistArray(body) {
	if (Array.isArray(body)) {
		return body
	}
	if (body !== null && typeof body === 'object' && Array.isArray(body.results)) {
		return body.results
	}
	return []
}

export default {
	name: 'ChecklistTemplatesTab',

	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcIconSvgWrapper,
		CnConfirmDialog,
		CnFormDialog,
		CnObjectRow,
		CnRowActions,
	},

	props: {
		/**
		 * Leave out the tab's own New checklist button. The admin page puts
		 * that action in the section header and calls `openEditor(null)`.
		 */
		hideAddButton: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			checklists: [],
			loading: false,
			editing: false,
			saving: false,
			editingChecklist: null,
			showDeleteConfirm: false,
			pendingDeleteChecklist: null,
			clipboardIcon:
				'<svg viewBox="0 0 24 24"><path fill="currentColor" d="M19,3H14.82C14.25,1.44 12.53,0.64 11,1.2C10.14,1.5 9.5,2.16 9.18,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M12,3A1,1 0 0,1 13,4A1,1 0 0,1 12,5A1,1 0 0,1 11,4A1,1 0 0,1 12,3M7,7H17V5H19V19H5V5H7V7Z" /></svg>',
		}
	},

	computed: {
		/**
		 * The checklists as list rows: the name, its version and size, and its
		 * state as a status pill (success when active, neutral when not).
		 *
		 * @return {Array<object>} One row per checklist.
		 * @spec openspec/specs/vth-checklists-as-rows/spec.md#requirement-the-vth-checklists-are-library-rows-with-a-status-pill
		 */
		rows() {
			return this.checklists.map((checklist) => {
				const items = Array.isArray(checklist.items) ? checklist.items.length : 0
				return {
					id: checklist.id,
					name: checklist.name,
					subtitle: t('dossiq', 'Version {version}', { version: checklist.version || 1 })
						+ ' · ' + n('dossiq', '%n item', '%n items', items),
					state: checklist.active ? t('dossiq', 'Active') : t('dossiq', 'Inactive'),
					tone: checklist.active ? 'success' : 'default',
					checklist,
				}
			})
		},

		/**
		 * The template as a form: its own fields, and `items` as an editable
		 * table of sub-objects (question, answer type, required, weight).
		 *
		 * @return {object} A JSON schema for CnFormDialog.
		 * @spec openspec/specs/inspection-checklists/spec.md#inspection-checklist-admin-ui
		 */
		templateSchema() {
			return {
				title: t('dossiq', 'Checklist'),
				type: 'object',
				required: ['name'],
				properties: {
					name: { type: 'string', title: t('dossiq', 'Name'), order: 1 },
					version: { type: 'integer', title: t('dossiq', 'Version'), minimum: 1, default: 1, order: 2 },
					active: { type: 'boolean', title: t('dossiq', 'Active'), default: false, order: 3 },
					validFrom: { type: 'string', format: 'date', title: t('dossiq', 'Valid from'), order: 4 },
					items: {
						type: 'array',
						title: t('dossiq', 'Checklist items'),
						'x-widget': 'sub-objects',
						order: 5,
						items: {
							type: 'object',
							title: t('dossiq', 'Item'),
							required: ['question'],
							properties: {
								question: { type: 'string', title: t('dossiq', 'Question or instruction'), order: 1 },
								type: {
									type: 'string',
									title: t('dossiq', 'Answer type'),
									enum: ['boolean', 'text', 'enum', 'photo'],
									'x-enum-labels': {
										boolean: t('dossiq', 'Yes/No'),
										text: t('dossiq', 'Text'),
										enum: t('dossiq', 'Multiple choice'),
										photo: t('dossiq', 'Photo'),
									},

									default: 'boolean',
									order: 2,
								},

								required: { type: 'boolean', title: t('dossiq', 'Required'), default: false, order: 3 },
								weight: { type: 'integer', title: t('dossiq', 'Weight'), minimum: 0, maximum: 100, default: 1, order: 4 },
							},
						},
					},
				},
			}
		},

		/** @return {object} Which row fields CnObjectRow draws where. */
		rowConfig() {
			return { titleField: 'name', subtitleField: 'subtitle', badgeField: 'state', badgeVariantField: 'tone' }
		},

		/** @return {Array<object>} The row menu: Edit, then Delete. */
		rowActions() {
			return [
				{ id: 'edit', label: t('dossiq', 'Edit'), handler: (checklist) => this.openEditor(checklist) },
				{ id: 'delete', label: t('dossiq', 'Delete'), destructive: true, handler: (checklist) => this.confirmDelete(checklist) },
			]
		},
	},

	mounted() {
		this.loadChecklists()
	},

	methods: {
		/**
		 * Load the checklist collection for the admin list.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inspection-checklists/spec.md#inspection-checklist-admin-ui
		 */
		async loadChecklists() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl(COLLECTION_URL))
				this.checklists = asChecklistArray(response.data)
			} catch {
				showError(t('dossiq', 'Failed to load checklists'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the form dialog on a template, or on a new one.
		 *
		 * @param {object|null} checklist The template to edit, or null for a new one.
		 * @return {void}
		 *
		 * @spec openspec/specs/inspection-checklists/spec.md#inspection-checklist-admin-ui
		 */
		openEditor(checklist) {
			this.editingChecklist = checklist
				? { ...checklist }
				: {
						name: '',
						version: 1,
						caseTypeRef: '',
						items: [],
						active: false,
						validFrom: new Date().toISOString().split('T')[0],
					}
			this.editing = true
		},

		/**
		 * Close the checklist editor and drop the working copy.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/inspection-checklists/spec.md#inspection-checklist-admin-ui
		 */
		closeEditor() {
			this.editing = false
			this.editingChecklist = null
		},

		/**
		 * Create or update a checklist, then reload the list. A refusal stays in
		 * the dialog with the server's sentence, so nothing typed is lost.
		 *
		 * @param {object} formData The form's values; an `id` selects update
		 *                          (PUT) over create (POST).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inspection-checklists/spec.md#inspection-checklist-admin-ui
		 */
		async saveChecklist(formData) {
			const checklist = { ...this.editingChecklist, ...formData }
			this.saving = true
			try {
				const url = checklist.id
					? generateUrl(
							COLLECTION_URL + '/' + encodeURIComponent(checklist.id),
						)
					: generateUrl(COLLECTION_URL)
				const method = checklist.id ? 'put' : 'post'
				await axios[method](url, checklist)
				showSuccess(t('dossiq', 'Checklist saved'))
				this.$refs.formDialog?.setResult({ success: true })
				this.closeEditor()
				await this.loadChecklists()
			} catch (e) {
				showError(t('dossiq', 'Failed to save checklist'))
				this.$refs.formDialog?.setResult({
					error: e?.response?.data?.message || t('dossiq', 'Failed to save checklist'),
				})
			} finally {
				this.saving = false
			}
		},

		/**
		 * Open the delete-confirmation dialog for a checklist.
		 *
		 * @param {object} checklist Checklist row.
		 * @return {void}
		 *
		 * @spec openspec/changes/vth-module/tasks.md#task-5
		 */
		confirmDelete(checklist) {
			this.pendingDeleteChecklist = checklist
			this.showDeleteConfirm = true
		},

		/**
		 * Confirm-handler for the CnConfirmDialog opened by confirmDelete().
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/vth-module/tasks.md#task-5
		 */
		async onConfirmDelete() {
			const checklist = this.pendingDeleteChecklist
			try {
				const url = generateUrl(
					COLLECTION_URL + '/' + encodeURIComponent(checklist.id),
				)
				await axios.delete(url)
				showSuccess(t('dossiq', 'Checklist deleted'))
				this.showDeleteConfirm = false
				await this.loadChecklists()
			} catch {
				showError(t('dossiq', 'Failed to delete checklist'))
				this.$refs.deleteConfirmDialog.setResult({
					error: t('dossiq', 'Failed to delete checklist'),
				})
			}
		},
	},
}
</script>

<style scoped>
.checklists-tab__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

/* DqVthInstellingen: the rows start at the card's content edge, under a
   line that closes the list off from the title. */
.checklists-tab__list {
	display: flex;
	flex-direction: column;
	border-top: 1px solid var(--color-border);
}

.checklists-tab__item.checklists-tab__item {
	padding-inline: 0;
}

.checklists-tab__item + .checklists-tab__item {
	border-top: 1px solid var(--color-border);
}
</style>
