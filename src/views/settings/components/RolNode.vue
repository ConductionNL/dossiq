<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="rol-node">
		<div class="rol-node__row">
			<span class="rol-node__name">{{ role.naam || role.id }}</span>
			<span v-if="role.type" class="rol-node__pill">{{ role.type }}</span>
			<span v-if="role.mandaatNiveau" class="rol-node__pill rol-node__pill--alt">{{ t('procest', 'level {n}', { n: role.mandaatNiveau }) }}</span>
			<span v-if="role.afdeling" class="rol-node__pill">{{ role.afdeling }}</span>
			<span v-if="role.team" class="rol-node__pill">{{ role.team }}</span>
			<div class="rol-node__actions">
				<NcButton size="small" @click="onEdit(role)">
					{{ t('procest', 'Edit') }}
				</NcButton>
				<NcButton size="small" type="error" @click="onDelete(role)">
					{{ t('procest', 'Delete') }}
				</NcButton>
			</div>
		</div>
		<div v-if="children.length > 0" class="rol-node__children">
			<RolNode
				v-for="c in children"
				:key="c.id"
				:role="c"
				:children-by-parent="childrenByParent"
				:on-edit="onEdit"
				:on-delete="onDelete" />
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

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
.rol-node {
	padding: 4px 0;
}

.rol-node__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-radius: 4px;
}

.rol-node__row:hover {
	background: var(--color-background-hover);
}

.rol-node__name {
	font-weight: 500;
}

.rol-node__pill {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.rol-node__pill--alt {
	background: var(--color-primary-light);
}

.rol-node__actions {
	display: flex;
	gap: 4px;
	margin-left: auto;
}

.rol-node__children {
	margin-left: 24px;
	border-left: 2px solid var(--color-border);
	padding-left: 8px;
}
</style>
