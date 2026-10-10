<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The pipelinq programme a case hangs under (board DqZaakPartijen, the
	Programma card), a section of the Related tab.

	🔴 DOSSIQ HOLDS NO PROGRAMME. pipelinq holds the programme and a work item
	naming this case as `dossiq:case`; this section reads them through dossiq's
	controller and shows the name and the progress. No budget, no copy.

	🔴 A FIGURE WITHOUT ITS MODE IS NOT A FIGURE, AND UNCOMPUTABLE IS NOT ZERO.
	The progress sentence names how it was measured, and when pipelinq says it
	cannot compute one the bar is not drawn at all: an empty bar reads as
	"nothing done yet".

	@spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
-->
<template>
	<div class="case-programme" data-testid="case-programme">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="failed"
			:name="t('dossiq', 'The programme could not be read')" />

		<NcEmptyContent
			v-else-if="!available"
			data-testid="case-programme-absent"
			:name="t('dossiq', 'Pipelinq is not installed on this instance')"
			:description="t('dossiq', 'A case can only hang under a programme when pipelinq is here.')" />

		<template v-else>
			<div v-if="programme" class="case-programme__current" data-testid="case-programme-current">
				<strong>{{ programme.name || programme.id }}</strong>
				<span
					v-if="computable"
					class="case-programme__bar"
					role="progressbar"
					:aria-valuenow="programme.progress.progress"
					aria-valuemin="0"
					aria-valuemax="100"
					:aria-label="t('dossiq', 'Progress of the programme')">
					<span class="case-programme__fill" :style="{ width: programme.progress.progress + '%' }" />
				</span>
				<span data-testid="case-programme-progress">{{ progressLine(programme.progress) }}</span>
			</div>
			<template v-else>
				<p class="case-programme__hint" data-testid="case-programme-none">
					{{ t('dossiq', 'This case does not hang under a programme.') }}
				</p>
				<NcButton data-testid="case-programme-link" @click="linking = true">
					{{ t('dossiq', 'Link to a programme') }}
				</NcButton>
			</template>
		</template>

		<LinkProgrammeDialog
			v-if="linking"
			:caseId="caseId"
			:caseTitle="caseTitle"
			@linked="load"
			@close="linking = false" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import LinkProgrammeDialog from '../../dialogs/LinkProgrammeDialog.vue'
import { fetchCaseProgramme, progressLine } from '../../services/pipelinqCaseApi.js'

export default {
	name: 'CaseProgrammeSection',

	components: { LinkProgrammeDialog, NcButton, NcEmptyContent, NcLoadingIcon },

	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},

		/** The loaded case, when the host hands it over. */
		object: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			loading: true,
			failed: false,
			available: false,
			programme: null,
			linking: false,
		}
	},

	computed: {
		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08 */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08 */
		caseTitle() {
			return String(this.object?.title || '')
		},

		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08 */
		computable() {
			const progress = this.programme?.progress
			return progress?.computable === true && typeof progress.progress === 'number'
		},
	},

	watch: {
		caseId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,
		progressLine,

		/**
		 * Read the programme this case hangs under.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
		 */
		async load() {
			if (this.caseId === '') {
				this.loading = false
				return
			}

			this.loading = true
			this.failed = false
			try {
				const answer = await fetchCaseProgramme(this.caseId)
				this.available = answer?.available === true
				this.programme = answer?.programme || null
			} catch {
				this.failed = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.case-programme {
	display: flex;
	flex-direction: column;
	gap: 10px;

	&__current {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__bar {
		display: block;
		height: 6px;
		border-radius: 3px;
		background: var(--color-background-darker);
		overflow: hidden;
	}

	&__fill {
		display: block;
		height: 6px;
		background: var(--color-primary-element);
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}
}
</style>
