<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The shell every grant step dialog of a case page shares: the dialog, the
  line while the case's grant chain loads, the line when there is nothing to
  act on, a refusal as the server words it, and Cancel beside the step's own
  button. The step's fields go in the default slot, its icon in `icon`.

  @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
-->
<template>
	<NcDialog
		:name="name"
		:data-testid="testid"
		@closing="$emit('close')">
		<div class="grant-step">
			<p v-if="loading" :data-testid="`${testid}-loading`">
				{{ t('dossiq', 'Loading the grants of this case …') }}
			</p>
			<p v-else-if="empty !== '' && error === ''" :data-testid="`${testid}-empty`">
				{{ empty }}
			</p>
			<slot v-else-if="empty === ''" />
			<p class="grant-step__note">
				<slot name="note" />
			</p>
			<p
				v-if="error"
				class="grant-step__error"
				role="alert"
				:data-testid="`${testid}-error`">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton :data-testid="`${testid}-cancel`" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				:data-testid="`${testid}-submit`"
				:disabled="!canSubmit || saving"
				variant="primary"
				@click="$emit('submit')">
				<template #icon>
					<slot name="icon" />
				</template>
				{{ saving ? t('dossiq', 'Saving …') : submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

export default {
	name: 'GrantStepDialog',

	components: { NcButton, NcDialog },

	props: {
		/** The dialog's title. */
		name: { type: String, required: true },
		/** The test id of the dialog; its parts are named after it. */
		testid: { type: String, required: true },
		/** The label of the step's button. */
		submitLabel: { type: String, required: true },
		/** Whether the grant chain is still loading. */
		loading: { type: Boolean, default: false },
		/** What to say when there is nothing to act on; empty while there is. A failed read shows its refusal instead. */
		empty: { type: String, default: '' },
		/** A refusal, as the server words it. */
		error: { type: String, default: '' },
		/** Whether the step may be taken with what is entered. */
		canSubmit: { type: Boolean, default: false },
		/** Whether the step is being sent. */
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'submit'],

	methods: { t },
}
</script>

<style scoped>
.grant-step {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.grant-step__note {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.grant-step__note:empty {
	display: none;
}

.grant-step__error {
	color: var(--color-error-text);
}
</style>
