<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Per-betrokkene inzageverzoek export entry point (AVG art. 15). Delegates
  entirely to OpenRegister's per-subject extract (OR-PA-7): OR produces the
  extract scoped to the caller's tenant, includes activity purpose + legal
  basis per entry, logs the export action itself, and denies non-FG users
  (OR-PA-8). Procest only renders the form and offers the result as a JSON
  download — no procest-side log query or storage.

  @spec openspec/specs/avg-verwerkingenlogging/spec.md
-->
<template>
	<NcModal size="normal" :name="t('procest', 'Data subject access export')" @close="$emit('close')">
		<div class="inzage-export">
			<!-- No <h2> here: NcModal's `name` prop already renders the dialog
			     heading (h2.modal-header__name) and wires it as the dialog's
			     accessible name. Repeating it in the body announced the title
			     twice to a screen reader and made every
			     getByRole('heading', …) query ambiguous. -->
			<p class="inzage-export__hint">
				{{ t('procest', 'Produces the per-subject processing extract from OpenRegister (AVG art. 15). The export itself is logged.') }}
			</p>

			<NcSelect
				v-model="idType"
				class="inzage-export__field"
				:options="idTypes"
				:input-label="t('procest', 'Subject identifier type')"
				:clearable="false" />

			<NcTextField
				class="inzage-export__field"
				v-model="idValue"
				:label="t('procest', 'Subject identifier value')"
				:placeholder="t('procest', 'e.g. a BSN or contact reference')" />

			<div v-if="error" class="inzage-export__error" role="alert">
				{{ error }}
			</div>

			<div v-if="result" class="inzage-export__result" data-testid="inzage-result">
				<p>
					{{ n('procest', '%n logged processing found for this subject.', '%n logged processings found for this subject.', result.count) }}
				</p>
				<NcButton type="secondary" @click="download">
					{{ t('procest', 'Download extract (JSON)') }}
				</NcButton>
			</div>

			<div class="inzage-export__actions">
				<NcButton type="tertiary" @click="$emit('close')">
					{{ t('procest', 'Close') }}
				</NcButton>
				<NcButton type="primary" :disabled="loading || !idValue" @click="run">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
					</template>
					{{ t('procest', 'Produce extract') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcSelect, NcTextField } from '@nextcloud/vue'
import { fetchBetrokkeneExtract } from '../services/verwerkingenApi.js'

export default {
	name: 'InzageExportModal',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			idType: 'BSN',
			idTypes: ['BSN', 'contact', 'email', 'burger'],
			idValue: '',
			loading: false,
			error: null,
			result: null,
		}
	},
	methods: {
		/** @spec openspec/specs/avg-verwerkingenlogging/spec.md */
		async run() {
			this.loading = true
			this.error = null
			this.result = null
			try {
				this.result = await fetchBetrokkeneExtract({ idType: this.idType, idValue: this.idValue.trim() })
			} catch (err) {
				if (err.response && err.response.status === 403) {
					this.error = t('procest', 'Privacy-officer or admin privileges are required for this export.')
				} else {
					this.error = t('procest', 'The extract could not be produced. Please try again.')
				}
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/specs/avg-verwerkingenlogging/spec.md */
		download() {
			const blob = new Blob([JSON.stringify(this.result, null, 2)], { type: 'application/json' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = `inzage-${this.idType}-${this.idValue.trim()}.json`
			a.click()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped lang="scss">
.inzage-export {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__error {
		color: var(--color-error);
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
