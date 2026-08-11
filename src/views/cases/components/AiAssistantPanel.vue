<template>
	<div class="ai-assistant-panel">
		<h3 class="ai-assistant-panel__title">
			{{ t('procest', 'AI Assistant') }}
		</h3>

		<!-- Q&A Section -->
		<div class="ai-assistant-panel__section">
			<h4>{{ t('procest', 'Questions') }}</h4>
			<div class="ai-assistant-panel__chat">
				<div
					v-for="(msg, idx) in conversation"
					:key="idx"
					class="ai-assistant-panel__message"
					:class="`ai-assistant-panel__message--${msg.role}`">
					<p>{{ msg.content }}</p>
					<div v-if="msg.sources && msg.sources.length" class="ai-assistant-panel__sources">
						<small v-for="(src, si) in msg.sources" :key="si">
							{{ src.document }} ({{ src.section }})
						</small>
					</div>
				</div>
			</div>
			<div class="ai-assistant-panel__input">
				<NcTextField
					:model-value="question"
					:aria-label="t('procest', 'Ask a question about this case...')"
					:placeholder="t('procest', 'Ask a question about this case...')"
					@update:model-value="v => question = v"
					@keydown.enter="askQuestion" />
				<NcButton :disabled="!question || askLoading" @click="askQuestion">
					{{ t('procest', 'Ask') }}
				</NcButton>
			</div>
		</div>

		<!-- Suggestions Section -->
		<div class="ai-assistant-panel__section">
			<h4>{{ t('procest', 'Suggestions') }}</h4>
			<NcLoadingIcon v-if="suggestionsLoading" :size="20" />
			<AiSuggestionCard
				v-for="(sug, idx) in suggestions"
				:key="idx"
				:suggestion="sug"
				@accept="handleAccept"
				@reject="handleReject" />
			<p v-if="!suggestionsLoading && suggestions.length === 0" class="ai-assistant-panel__empty">
				{{ t('procest', 'No suggestions available') }}
			</p>
		</div>

		<!-- Summary Section -->
		<div class="ai-assistant-panel__section">
			<h4>{{ t('procest', 'Case Summary') }}</h4>
			<NcButton v-if="!summaryText" :disabled="summaryLoading" @click="loadSummary">
				{{ t('procest', 'Generate summary') }}
			</NcButton>
			<NcLoadingIcon v-if="summaryLoading" :size="20" />
			<p v-if="summaryText" class="ai-assistant-panel__summary">
				{{ summaryText }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { askQuestion as askApi, suggestNext, summarize } from '../../../services/aiApi.js'
import AiSuggestionCard from './AiSuggestionCard.vue'

export default {
	name: 'AiAssistantPanel',
	components: { NcButton, NcTextField, NcLoadingIcon, AiSuggestionCard },
	props: {
		caseId: { type: String, required: true },
	},
	data() {
		return {
			question: '',
			askLoading: false,
			conversation: [],
			suggestions: [],
			suggestionsLoading: false,
			summaryText: '',
			summaryLoading: false,
		}
	},
	mounted() {
		this.loadSuggestions()
	},
	methods: {
		t,
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		async askQuestion() {
			if (!this.question) return
			const q = this.question
			this.question = ''
			this.conversation.push({ role: 'user', content: q })
			this.askLoading = true
			try {
				const response = await askApi(this.caseId, q)
				this.conversation.push({
					role: 'assistant',
					content: response.answer || t('procest', 'No relevant information found'),
					sources: response.sources || [],
				})
			} catch (e) {
				this.conversation.push({
					role: 'assistant',
					content: t('procest', 'Failed to get an answer. Please try again.'),
				})
			} finally {
				this.askLoading = false
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		async loadSuggestions() {
			this.suggestionsLoading = true
			try {
				const response = await suggestNext(this.caseId)
				this.suggestions = response.suggestions || []
			} catch (e) {
				this.suggestions = []
			} finally {
				this.suggestionsLoading = false
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		async loadSummary() {
			this.summaryLoading = true
			try {
				const response = await summarize(this.caseId, 'case')
				this.summaryText = response.summary || ''
			} catch (e) {
				this.summaryText = t('procest', 'Summary generation failed.')
			} finally {
				this.summaryLoading = false
			}
		},
		/**
		 * @param suggestion
		 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
		 */
		handleAccept(suggestion) {
			this.$emit('suggestion-accepted', suggestion)
		},
		/**
		 * @param suggestion
		 * @param reason
		 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
		 */
		handleReject(suggestion, reason) {
			this.$emit('suggestion-rejected', suggestion, reason)
		},
	},
}
</script>

<style scoped>
.ai-assistant-panel {
	padding: 12px;
}

.ai-assistant-panel__section {
	margin-bottom: 20px;
}

.ai-assistant-panel__chat {
	max-height: 300px;
	overflow-y: auto;
	margin-bottom: 8px;
}

.ai-assistant-panel__message {
	padding: 8px 12px;
	margin: 4px 0;
	border-radius: var(--border-radius);
}

.ai-assistant-panel__message--user {
	background-color: var(--color-primary-element-light);
	text-align: right;
}

.ai-assistant-panel__message--assistant {
	background-color: var(--color-background-dark);
}

.ai-assistant-panel__sources small {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}

.ai-assistant-panel__input {
	display: flex;
	gap: 8px;
}

.ai-assistant-panel__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.ai-assistant-panel__summary {
	white-space: pre-wrap;
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
}
</style>
