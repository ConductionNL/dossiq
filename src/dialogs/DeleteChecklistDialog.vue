<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="t('procest', 'Delete checklist')"
		:message="t('procest', 'Are you sure you want to delete \'{name}\'?', { name: checklist ? checklist.name : '' })"
		@close="$emit('cancel')">
		<template #actions>
			<NcButton type="error" @click="$emit('confirm')">
				{{ t('procest', 'Delete') }}
			</NcButton>
			<NcButton @click="$emit('cancel')">
				{{ t('procest', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'DeleteChecklistDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/** The checklist to be deleted, or null. */
		checklist: {
			type: Object,
			default: null,
		},
	},

	emits: ['confirm', 'cancel'],

	methods: { t },
}
</script>
