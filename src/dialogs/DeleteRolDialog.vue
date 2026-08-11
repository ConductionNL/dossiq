<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="t('procest', 'Delete role')"
		:open="!!role"
		@update:open="v => { if (!v) $emit('close') }">
		<p>{{ t('procest', 'Delete role {n}?', { n: role.naam || role.id }) }}</p>
		<p v-if="blockedReason" class="rol-manager__warning">
			{{ blockedReason }}
		</p>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton type="error" :disabled="!!blockedReason" @click="$emit('confirm')">
				{{ t('procest', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

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
.rol-manager__warning {
	color: var(--color-error);
	font-size: 12px;
}
</style>
