<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="role-manager">
		<div class="role-manager__toolbar">
			<NcButton variant="primary" @click="openEditor(null)">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('dossiq', 'New role') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-if="!loading && roles.length === 0"
			:name="t('dossiq', 'No organisational roles')"
			:description="
				t(
					'dossiq',
					'Define roles to build a mandate hierarchy. Roles can have parents (afdeling/team) and a mandaat level.',
				)
			">
			<template #icon>
				<AccountGroup :size="48" />
			</template>
		</NcEmptyContent>

		<!-- The role hierarchy, every level open, with each role's kind, mandate level and unit beside its name. -->
		<CnTreeView
			v-if="!loading && roots.length > 0"
			class="role-manager__tree"
			:nodes="tree"
			:expandedIds="expandedIds"
			:expandLabel="t('dossiq', 'Expand')"
			:collapseLabel="t('dossiq', 'Collapse')"
			@update:expandedIds="expandedIds = $event">
			<template #actions="{ node }">
				<span v-if="node.role.type" class="role-manager__pill">{{ node.role.type }}</span>
				<span v-if="node.role.mandateLevel" class="role-manager__pill role-manager__pill--alt">{{
					t('dossiq', 'level {n}', { n: node.role.mandateLevel })
				}}</span>
				<span v-if="node.role.department" class="role-manager__pill">{{ node.role.department }}</span>
				<span v-if="node.role.team" class="role-manager__pill">{{ node.role.team }}</span>
				<span class="role-manager__actions">
					<NcButton size="small" @click.stop="openEditor(node.role)">
						{{ t('dossiq', 'Edit') }}
					</NcButton>
					<NcButton size="small" variant="error" @click.stop="confirmDelete(node.role)">
						{{ t('dossiq', 'Delete') }}
					</NcButton>
				</span>
			</template>
		</CnTreeView>

		<RolEditorDialog
			v-if="editorOpen"
			:role="editingRole"
			:parentOptions="parentOptions"
			@save="onSave"
			@close="closeEditor" />

		<DeleteRolDialog
			v-if="deleting"
			:role="deleting"
			:blockedReason="deleteBlockedReason"
			@close="deleting = null"
			@confirm="doDelete" />
	</div>
</template>

<script>
import { CnTreeView } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import DeleteRolDialog from '../../../dialogs/DeleteRolDialog.vue'
import RolEditorDialog from '../../../dialogs/RolEditorDialog.vue'

export default {
	name: 'RoleTreeManager',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Plus,
		AccountGroup,
		CnTreeView,
		RolEditorDialog,
		DeleteRolDialog,
	},

	props: {
		roles: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
	},

	emits: ['reload'],
	data() {
		return {
			expandedIds: [],
			editorOpen: false,
			editingRole: null,
			deleting: null,
		}
	},

	computed: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		childrenByParent() {
			const map = {}
			for (const r of this.roles) {
				const p = r.parentRole || ''
				if (!map[p]) map[p] = []
				map[p].push(r)
			}
			return map
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		roots() {
			return (this.roles || []).filter((r) => !r.parentRole)
		},

		/**
		 * The roles as tree nodes, each under its parent role.
		 *
		 * @return {Array<object>} Root nodes `{ id, label, role, children }`.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		tree() {
			const toNode = (role, seen) => ({
				id: role.id,
				label: role.name || role.id,
				role,
				children: seen.has(role.id)
					? []
					: (this.childrenByParent[role.id] || []).map((child) => toNode(child, new Set([...seen, role.id]))),
			})
			return this.roots.map((role) => toNode(role, new Set()))
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		parentOptions() {
			return [
				{ id: '', label: t('dossiq', '(top level)') },
				...this.roles.map((r) => ({ id: r.id, label: r.name || r.id })),
			]
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		deleteBlockedReason() {
			if (!this.deleting) return ''
			const refsAsParent = this.roles.some(
				(r) => r.parentRole === this.deleting.id,
			)
			if (refsAsParent)
				return t(
					'dossiq',
					'Cannot delete: this role is the parent of other roles. Re-parent them first.',
				)
			return ''
		},
	},

	watch: {
		/**
		 * Open every level whenever the roles change, as the hierarchy was
		 * always shown whole.
		 *
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		roles: {
			immediate: true,
			/**
			 * Expand every role id the list holds.
			 *
			 * @param {Array<object>} roles The roles.
			 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
			 */
			handler(roles) {
				this.expandedIds = (roles || []).map((role) => role.id)
			},
		},
	},

	methods: {
		t,
		/**
		 * @param {object} role The role.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		openEditor(role) {
			this.editingRole = role
			this.editorOpen = true
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		closeEditor() {
			this.editorOpen = false
			this.editingRole = null
		},

		/**
		 * @param {object} role The role.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		confirmDelete(role) {
			this.deleting = role
		},

		/**
		 * @param {object} payload The payload.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		async onSave(payload) {
			try {
				if (this.editingRole && this.editingRole.id) {
					await axios.patch(
						generateUrl(
							'/apps/dossiq/api/mandate/rollen/'
								+ encodeURIComponent(this.editingRole.id),
						),
						payload,
					)
				} else {
					await axios.post(
						generateUrl('/apps/dossiq/api/mandate/rollen'),
						payload,
					)
				}
				this.closeEditor()
				this.$emit('reload')
			} catch {
				// Surface in dialog; leave open.
			}
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async doDelete() {
			if (!this.deleting) return
			try {
				await axios.delete(
					generateUrl(
						'/apps/dossiq/api/mandate/rollen/'
							+ encodeURIComponent(this.deleting.id),
					),
				)
			} catch {
				/* silent — user can retry */
			}
			this.deleting = null
			this.$emit('reload')
		},
	},
}
</script>

<style scoped>
.role-manager__toolbar {
	margin-bottom: 12px;
}

.role-manager__tree {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	background: var(--color-main-background);
}

.role-manager__pill {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.role-manager__pill--alt {
	background: var(--color-primary-element-light);
}

.role-manager__actions {
	display: flex;
	gap: 4px;
	margin-inline-start: auto;
}
</style>
