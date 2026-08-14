<template>
	<div class="ai-summary-panel">
		<div class="ai-summary-panel__header">
			<h4>{{ t('procest', 'AI Summary') }}</h4>
			<NcButton :disabled="loading" @click="generate">
				{{
					loading
						? t('procest', 'Generating...')
						: t('procest', 'Generate')
				}}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="20" />

		<div v-if="summary" class="ai-summary-panel__content">
			<p>{{ summary }}</p>
			<NcButton @click="saveAsNote">
				{{ t('procest', 'Save as case note') }}
			</NcButton>
		</div>

		<div v-if="error">
			<NcNoteCard type="error">
				{{ error }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { summarize } from '../../../services/aiApi.js'

export default {
	name: 'AiSummaryPanel',
	components: { NcButton, NcLoadingIcon, NcNoteCard },
	props: {
		caseId: { type: String, required: true },
		type: {
			type: String,
			default: 'case',
			validator: (v) => ['case', 'document', 'timeline'].includes(v),
		},
		documentId: { type: String, default: null },
	},
	emits: ['save-note'],
	data() {
		return { loading: false, summary: '', error: null }
	},
	methods: {
		t,
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		async generate() {
			this.loading = true
			this.error = null
			try {
				const response = await summarize(
					this.caseId,
					this.type,
					this.documentId,
				)
				this.summary = response.summary || ''
			} catch (e) {
				this.error =
					e.response?.data?.error
					|| t('procest', 'Summary generation failed')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		saveAsNote() {
			this.$emit('save-note', this.summary)
		},
	},
}
</script>

<style scoped>
.ai-summary-panel__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.ai-summary-panel__content {
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
}

.ai-summary-panel__content p {
	white-space: pre-wrap;
	margin-bottom: 8px;
}
</style>
