<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Put a case under a pipelinq programme (board DqZaakPartijen, "Aan een
	programma koppelen").

	🔴 THE REFUSAL NAMES THE HOLDER AND KEEPS THE DIALOG OPEN. pipelinq refuses
	a case that already hangs under another programme and says which one; that
	sentence is shown as is, because the handler has to know where to take the
	case out first.

	@spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Link to a programme')"
		data-testid="link-programme-dialog"
		@closing="$emit('close')">
		<div class="link-programme">
			<NcLoadingIcon v-if="loading" :size="24" />
			<p
				v-else-if="programmes.length === 0"
				class="link-programme__hint"
				data-testid="link-programme-none">
				{{ t('dossiq', 'Pipelinq holds no programme you can choose.') }}
			</p>
			<fieldset v-else class="link-programme__group">
				<legend>{{ t('dossiq', 'Programme') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="programme in programmes"
					:key="programme.id"
					type="radio"
					name="link-programme"
					:modelValue="chosen"
					:value="programme.id"
					data-testid="link-programme-option"
					@update:modelValue="chosen = $event">
					{{ programme.name || programme.id }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<p
				v-if="error"
				class="link-programme__error"
				role="alert"
				data-testid="link-programme-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="link-programme-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="link-programme-confirm"
				:disabled="chosen === '' || busy"
				@click="confirm">
				{{ t('dossiq', 'Link') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import {
	fetchProgrammes,
	linkCaseProgramme,
	refusalOf,
} from '../services/pipelinqCaseApi.js'

export default {
	name: 'LinkProgrammeDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon },

	props: {
		/** The case to put under a programme. */
		caseId: {
			type: String,
			required: true,
		},

		/** The case title as the handler sees it, stored on the work item. */
		caseTitle: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'linked'],

	data() {
		return {
			loading: true,
			programmes: [],
			chosen: '',
			error: '',
			busy: false,
		}
	},

	async mounted() {
		try {
			const answer = await fetchProgrammes()
			this.programmes = Array.isArray(answer?.programmes)
				? answer.programmes
				: []
		} catch (error) {
			this.error = refusalOf(
				error,
				t('dossiq', 'The programmes could not be read.'),
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * Ask pipelinq, through dossiq, to hold this case under the chosen programme.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
		 */
		async confirm() {
			this.busy = true
			this.error = ''
			try {
				await linkCaseProgramme(this.caseId, this.chosen, this.caseTitle)
				this.$emit('linked')
				this.$emit('close')
			} catch (error) {
				this.error = refusalOf(
					error,
					t('dossiq', 'The case could not be linked to that programme.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.link-programme {
	display: flex;
	flex-direction: column;
	gap: 10px;

	&__group {
		border: 0;
		margin: 0;
		padding: 0;

		legend {
			font-weight: 600;
			margin-bottom: 4px;
		}
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}
}
</style>
