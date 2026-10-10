<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	File one contact moment onto another case too (board DqZaakContactmomenten,
	"Ook bij een andere zaak opnemen").

	🔴 IT STAYS ONE CONTACT MOMENT. The act is pipelinq's `fileOnAlsoCase`,
	reached through dossiq's controller, which checks that the handler may
	change BOTH cases. Nothing here writes the reference set or makes a copy.

	A refusal keeps the dialog open with pipelinq's own sentence, because "it
	is already on that case" and "you may not change that case" ask for
	different next steps.

	@spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Also file on another case')"
		data-testid="file-contact-moment-dialog"
		@closing="$emit('close')">
		<div class="file-contact-moment">
			<p class="file-contact-moment__moment">
				{{ momentLabel }}
			</p>

			<NcTextField
				v-model="query"
				data-testid="file-contact-moment-search"
				:label="t('dossiq', 'Case')"
				:placeholder="t('dossiq', 'Case number or title')"
				@update:modelValue="search" />

			<ul v-if="results.length > 0" class="file-contact-moment__results">
				<li v-for="row in results" :key="row.id">
					<NcCheckboxRadioSwitch
						type="radio"
						name="file-contact-moment-target"
						:modelValue="target"
						:value="row.id"
						data-testid="file-contact-moment-result"
						@update:modelValue="target = $event">
						{{ row.label }}
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
			<p v-else-if="searched" class="file-contact-moment__hint">
				{{ t('dossiq', 'No case found with those words.') }}
			</p>

			<p class="file-contact-moment__hint">
				{{
					t(
						'dossiq',
						'It stays one contact moment. You see it on both cases afterwards.',
					)
				}}
			</p>

			<p
				v-if="error"
				class="file-contact-moment__error"
				data-testid="file-contact-moment-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton
				data-testid="file-contact-moment-cancel"
				@click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="file-contact-moment-confirm"
				:disabled="target === '' || busy"
				@click="confirm">
				{{ t('dossiq', 'File') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { fileContactMoment, refusalOf } from '../services/pipelinqCaseApi.js'

export default {
	name: 'FileContactMomentDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField },

	props: {
		/** The case the moment is on now. */
		caseId: {
			type: String,
			required: true,
		},

		/** The contact moment, as the panel holds it. */
		moment: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'filed'],

	data() {
		return {
			query: '',
			results: [],
			searched: false,
			target: '',
			error: '',
			busy: false,
		}
	},

	computed: {
		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03 */
		momentLabel() {
			return [
				this.moment?.channel,
				this.moment?.subject || this.moment?.summary,
			]
				.filter(Boolean)
				.join(': ')
		},
	},

	methods: {
		t,

		/**
		 * Find the cases the handler may mean, leaving out the one it is on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
		 */
		async search() {
			const term = this.query.trim()
			if (term.length < 2) {
				this.results = []
				this.searched = false
				return
			}

			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/dossiq/case'),
					{ params: { _search: term, _limit: 10 } },
				)
				this.results = (data?.results || [])
					.filter((row) => String(row?.id ?? '') !== this.caseId)
					.map((row) => ({
						id: String(row?.id ?? ''),
						label: [row?.identifier, row?.title]
							.filter(Boolean)
							.join(' · '),
					}))
				this.error = ''
			} catch (error) {
				this.results = []
				this.error = refusalOf(
					error,
					t('dossiq', 'Those words could not be searched for.'),
				)
			} finally {
				this.searched = true
			}
		},

		/**
		 * Ask pipelinq, through dossiq, to file the moment onto the chosen case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
		 */
		async confirm() {
			this.busy = true
			this.error = ''
			try {
				await fileContactMoment(
					this.caseId,
					String(this.moment?.id || ''),
					this.target,
				)
				this.$emit('filed')
				this.$emit('close')
			} catch (error) {
				this.error = refusalOf(
					error,
					t(
						'dossiq',
						'The contact moment could not be filed on that case.',
					),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.file-contact-moment {
	display: flex;
	flex-direction: column;
	gap: 10px;

	&__results {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__moment,
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
