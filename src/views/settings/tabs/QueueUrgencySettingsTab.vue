<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="queue-urgency-settings" data-testid="queue-urgency-settings">
		<p class="queue-urgency-settings__intro">
			{{
				t(
					'dossiq',
					'Decides the order of the cases assigned to someone. A case type can set the two thresholds itself.',
				)
			}}
		</p>

		<div class="queue-urgency-settings__grid">
			<NcInputField
				v-for="field in fields"
				:key="field.field"
				v-model="values[field.field]"
				:label="field.label"
				:helperText="errors[field.field] || field.hint"
				:error="!!errors[field.field]"
				:data-testid="`queue-urgency-${field.field}`"
				type="text"
				inputmode="decimal" />
		</div>

		<NcNoteCard v-if="saveError" type="error">
			{{ saveError }}
		</NcNoteCard>
		<NcNoteCard v-else-if="saved" type="success">
			{{ t('dossiq', 'Saved') }}
		</NcNoteCard>

		<div class="queue-urgency-settings__actions">
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="queue-urgency-save"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('dossiq', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcInputField, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	buildQueueUrgencyPayload,
	initialQueueUrgencyValues,
} from '../../../utils/queueUrgencySettings.js'

/**
 * The queue urgency section of the dossiq admin settings: the thresholds for
 * Critical and Soon, and the weights of priority and lying still.
 *
 * Rendered inside the Nextcloud admin settings page, never as a route of the
 * app. Saves through the app's own admin-guarded settings write, the same one
 * the consultation section uses.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
export default {
	name: 'QueueUrgencySettingsTab',
	components: { NcButton, NcInputField, NcLoadingIcon, NcNoteCard },

	data() {
		return {
			values: initialQueueUrgencyValues(
				loadState('dossiq', 'queueUrgencySettings', {}),
			),

			errors: {},
			saving: false,
			saved: false,
			saveError: '',
		}
	},

	computed: {
		/**
		 * The four fields, with their labels and hints.
		 *
		 * @return {Array<{field: string, label: string, hint: string}>} The fields.
		 *
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		fields() {
			return [
				{
					field: 'criticalDays',
					label: t('dossiq', 'Critical from, working days left'),
					hint: t('dossiq', 'From 0 to 60. Default 3.'),
				},
				{
					field: 'warningDays',
					label: t('dossiq', 'Almost due from, working days left'),
					hint: t('dossiq', 'From 0 to 120. Default 7.'),
				},
				{
					field: 'priorityWeight',
					label: t('dossiq', 'Weight of the priority'),
					hint: t(
						'dossiq',
						'Points per step from low to urgent, 0 to 50. Default 10.',
					),
				},
				{
					field: 'idleWeight',
					label: t('dossiq', 'Weight of the time lying still'),
					hint: t(
						'dossiq',
						'Points per day without activity, 0 to 1.5. Default 0.5.',
					),
				},
			]
		},
	},

	methods: {
		t,

		/**
		 * Validate, then save. A refused value sends nothing.
		 *
		 * `fetch` does not reject on a non-2xx answer, so `res.ok` is checked:
		 * otherwise a 403 reads exactly like a save.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async save() {
			this.saved = false
			this.saveError = ''
			const { errors, payload } = buildQueueUrgencyPayload(this.values)
			this.errors = errors
			if (payload === null) {
				return
			}

			this.saving = true
			try {
				const res = await fetch(generateUrl('/apps/dossiq/api/settings'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(payload),
				})
				if (res.ok) {
					this.saved = true
				} else {
					this.saveError = t('dossiq', 'Saving failed ({status})', {
						status: res.status,
					})
				}
			} catch (e) {
				this.saveError = e.message || t('dossiq', 'Saving failed')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.queue-urgency-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 800px;
}

.queue-urgency-settings__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.queue-urgency-settings__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 16px;
}

.queue-urgency-settings__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
