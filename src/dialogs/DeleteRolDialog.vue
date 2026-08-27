<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Delete role')"
		:open="!!role"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<p>{{ t('dossiq', 'Delete role {n}?', { n: role.name || role.id }) }}</p>
		<p v-if="blockedReason" class="rol-manager__warning">
			{{ blockedReason }}
		</p>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				type="error"
				:disabled="!!blockedReason"
				@click="$emit('confirm')">
				{{ t('dossiq', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'DeleteRolDialog',
	components: { NcButton, NcDialog },
	props: {
		role: { type: Object, required: true },
		blockedReason: { type: String, default: '' },
	},

	emits: ['close', 'confirm'],
	methods: {
		t,
	},
}
</script>

<style scoped>
.role-manager__warning {
	color: var(--color-error);
	font-size: 12px;
}
</style>
