<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  How often a reviewer is reminded of the archival decisions they hold.

  THE SETTING IS OPENREGISTER'S AND DOSSIQ KEEPS NO COPY. This reads and writes
  `reviewReminderFrequency` on openregister's /api/settings/archival. A second
  store of the same value is one an administrator can change without changing
  when anybody is reminded, which is worse than not offering the field at all.

  The value is checked before it is sent, so a typo reads as a refused field
  rather than as a server error, and an empty box never silently writes nothing.

  @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
-->
<template>
	<div class="archival-settings" data-testid="archival-settings">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard
			v-else-if="error"
			type="error"
			data-testid="archival-settings-error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<NcTextField
				:modelValue="frequency"
				:label="t('dossiq', 'Review reminder frequency')"
				:helperText="
					t(
						'dossiq',
						'An ISO 8601 duration, for example P7D for every seven days.',
					)
				"
				:error="invalid"
				data-testid="archival-settings-frequency"
				@update:modelValue="(v) => (frequency = v)" />

			<NcButton
				:disabled="canSave === false"
				data-testid="archival-settings-save"
				@click="save">
				{{ t('dossiq', 'Save') }}
			</NcButton>

			<p v-if="saved" data-testid="archival-settings-saved">
				{{ t('dossiq', 'Saved.') }}
			</p>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	archivalSettings,
	isIsoDuration,
	saveReviewReminderFrequency,
} from '../../../services/archivalApi.js'

export default {
	name: 'ArchivalSettingsTab',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			error: '',
			frequency: '',
			saved: false,
			busy: false,
		}
	},

	computed: {
		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		invalid() {
			return isIsoDuration(this.frequency) === false
		},

		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		canSave() {
			return this.busy === false && this.invalid === false
		},
	},

	/**
	 * Read openregister's archival settings once the tab is on the page.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read the frequency openregister holds.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''

			try {
				const settings = await archivalSettings()
				this.frequency = String(settings.reviewReminderFrequency ?? 'P7D')
			} catch (e) {
				this.error = String(e?.message ?? e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Write the frequency to openregister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		async save() {
			if (this.canSave === false) {
				return
			}

			this.busy = true
			this.saved = false

			try {
				const settings = await saveReviewReminderFrequency(this.frequency)
				this.frequency = String(
					settings.reviewReminderFrequency ?? this.frequency,
				)
				this.saved = true
			} catch (e) {
				this.error = String(e?.message ?? e)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
