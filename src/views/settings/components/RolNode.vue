<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="rol-node">
		<div class="rol-node__row">
			<span class="rol-node__name">{{ role.name || role.id }}</span>
			<span v-if="role.type" class="rol-node__pill">{{ role.type }}</span>
			<span
				v-if="role.mandateLevel"
				class="rol-node__pill rol-node__pill--alt"
				>{{ t('dossiq', 'level {n}', { n: role.mandateLevel }) }}</span
			>
			<span v-if="role.department" class="rol-node__pill">{{
				role.department
			}}</span>
			<span v-if="role.team" class="rol-node__pill">{{ role.team }}</span>
			<div class="rol-node__actions">
				<NcButton size="small" @click="onEdit(role)">
					{{ t('dossiq', 'Edit') }}
				</NcButton>
				<NcButton size="small" type="error" @click="onDelete(role)">
					{{ t('dossiq', 'Delete') }}
				</NcButton>
			</div>
		</div>
		<div v-if="children.length > 0" class="rol-node__children">
			<RolNode
				v-for="c in children"
				:key="c.id"
				:role="c"
				:childrenByParent="childrenByParent"
				:onEdit="onEdit"
				:onDelete="onDelete" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'RolNode',
	components: { NcButton },
	props: {
		role: { type: Object, required: true },
		childrenByParent: { type: Object, default: () => ({}) },
		onEdit: { type: Function, required: true },
		onDelete: { type: Function, required: true },
	},

	computed: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		children() {
			return this.childrenByParent[this.role.id] || []
		},
	},

	methods: { t },
}
</script>

<style scoped>
.role-node {
	padding: 4px 0;
}

.role-node__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-radius: 4px;
}

.role-node__row:hover {
	background: var(--color-background-hover);
}

.role-node__name {
	font-weight: 500;
}

.role-node__pill {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.role-node__pill--alt {
	background: var(--color-primary-light);
}

.role-node__actions {
	display: flex;
	gap: 4px;
	margin-left: auto;
}

.role-node__children {
	margin-left: 24px;
	border-left: 2px solid var(--color-border);
	padding-left: 8px;
}
</style>
