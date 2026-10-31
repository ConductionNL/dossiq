<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Mandaat Matrix admin tab — wraps the 4 sub-tabs (Besluiten, Rollen,
  Toewijzingen, Import) for the mandaatmatrix-admin-ui spec.
-->
<template>
	<div class="mandaat-matrix-tab">
		<NcSelect
			class="mandaat-matrix-tab__sections"
			:modelValue="activeOption"
			:options="tabOptions"
			:inputLabel="t('dossiq', 'Sections')"
			:clearable="false"
			data-testid="mandaat-matrix-sections"
			@update:modelValue="(opt) => { if (opt) active = opt.id }" />

		<div class="mandaat-matrix-tab__body">
			<DelegationMatrixTable
				v-if="active === 'besluiten'"
				:matrices="matrices"
				:loading="loading"
				@edit="openEditor"
				@import="openImport" />
			<RoleTreeManager
				v-else-if="active === 'rollen'"
				:roles="roles"
				:loading="loading"
				@reload="loadRoles" />
			<RoleAssignmentsTable
				v-else-if="active === 'toewijzingen'"
				:assignments="assignments"
				:loading="loading"
				:roleOptions="roleOptions"
				@reload="loadAssignments" />
			<DelegationImportPanel
				v-else-if="active === 'import'"
				@imported="onImported" />
		</div>

		<MandaatEditor
			v-if="editorOpen"
			:mandaat="editingMandaat"
			:roleOptions="roleOptions"
			@save="onMandaatSave"
			@close="closeEditor" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import MandaatEditor from '../../../modals/MandaatEditor.vue'
import DelegationImportPanel from '../components/DelegationImportPanel.vue'
import DelegationMatrixTable from '../components/DelegationMatrixTable.vue'
import RoleAssignmentsTable from '../components/RoleAssignmentsTable.vue'
import RoleTreeManager from '../components/RoleTreeManager.vue'

export default {
	name: 'DelegationMatrixTab',
	components: {
		NcSelect,
		DelegationMatrixTable,
		RoleTreeManager,
		RoleAssignmentsTable,
		DelegationImportPanel,
		MandaatEditor,
	},

	data() {
		return {
			active: 'besluiten',
			loading: false,
			matrices: [],
			roles: [],
			assignments: [],
			editorOpen: false,
			editingMandaat: null,
		}
	},

	computed: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		tabOptions() {
			return [
				{ id: 'besluiten', label: t('dossiq', 'Decisions') },
				{ id: 'rollen', label: t('dossiq', 'Roles') },
				{ id: 'toewijzingen', label: t('dossiq', 'Assignments') },
				{ id: 'import', label: t('dossiq', 'Import') },
			]
		},

		/**
		 * The section shown, as the picker's value.
		 *
		 * @return {object} The option.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		activeOption() {
			return this.tabOptions.find((opt) => opt.id === this.active) || this.tabOptions[0]
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		roleOptions() {
			return (this.roles || []).map((r) => ({
				id: r.id,
				label: r.name || r.label || r.id,
			}))
		},
	},

	watch: {
		active: {
			immediate: true,
			/**
			 * @param {string|number|boolean|object} v The new value.
			 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
			 */
			handler(v) {
				if (v === 'besluiten') this.loadBesluiten()
				else if (v === 'rollen') this.loadRoles()
				else if (v === 'toewijzingen') this.loadAssignments()
			},
		},
	},

	methods: {
		t,
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async loadBesluiten() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/mandate/besluiten'),
				)
				this.matrices = Array.isArray(res.data)
					? res.data
					: res.data?.results || []
			} catch {
				this.matrices = []
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async loadRoles() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/mandate/rollen'),
				)
				this.roles = Array.isArray(res.data)
					? res.data
					: res.data?.results || []
			} catch {
				this.roles = []
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async loadAssignments() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/mandate/toewijzingen'),
				)
				this.assignments = Array.isArray(res.data)
					? res.data
					: res.data?.results || []
			} catch {
				this.assignments = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} mandaat The mandaat.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		openEditor(mandaat) {
			this.editingMandaat = mandaat
			this.editorOpen = true
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		closeEditor() {
			this.editorOpen = false
			this.editingMandaat = null
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		openImport() {
			this.active = 'import'
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		onImported() {
			this.active = 'besluiten'
			this.loadBesluiten()
		},

		/**
		 * @param {object} payload The payload.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		async onMandaatSave(payload) {
			try {
				if (this.editingMandaat && this.editingMandaat.id) {
					await axios.patch(
						generateUrl(
							'/apps/dossiq/api/mandate/mandaten/'
								+ encodeURIComponent(this.editingMandaat.id),
						),
						payload,
					)
				} else {
					await axios.post(
						generateUrl('/apps/dossiq/api/mandate/mandaten'),
						payload,
					)
				}
				this.closeEditor()
				this.loadBesluiten()
			} catch {
				// Editor surfaces the error; keep modal open by not closing.
			}
		},
	},
}
</script>

<style scoped>
/* A section of the admin card: the section picker is a settings field, as
   board DqBeheerGeavanceerd "Mandaatmatrix" draws it. */
.mandaat-matrix-tab__sections {
	width: 100%;
	max-width: 560px;
	margin-bottom: 14px;
}

/* The board look gives a labelled settings select -4px below, which wins over
   the picker's own margin; 18px here collapses with it to the drawn 14px. */
.mandaat-matrix-tab__body {
	margin-top: 18px;
	padding: 0;
}
</style>
