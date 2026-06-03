<template>
	<div class="public-case-view">
		<!-- Loading state -->
		<div v-if="loading" class="public-case-view__loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('procest', 'Loading case data...') }}</p>
		</div>

		<!-- Password prompt -->
		<div v-else-if="requiresPassword" class="public-case-view__password">
			<h2>{{ t('procest', 'Password required') }}</h2>
			<p>{{ t('procest', 'This shared case is password-protected.') }}</p>
			<div class="public-case-view__password-form">
				<NcTextField
					:value="password"
					type="password"
					:label="t('procest', 'Password')"
					@update:value="v => password = v"
					@keydown.enter="submitPassword" />
				<NcButton type="primary" @click="submitPassword">
					{{ t('procest', 'Access') }}
				</NcButton>
			</div>
			<p v-if="passwordError" class="public-case-view__error">
				{{ passwordError }}
			</p>
		</div>

		<!-- Error state -->
		<div v-else-if="error" class="public-case-view__error-page">
			<h2>{{ t('procest', 'Access denied') }}</h2>
			<p>{{ error }}</p>
		</div>

		<!-- Case data -->
		<div v-else-if="caseData" class="public-case-view__content">
			<header class="public-case-view__header">
				<h1>{{ caseData.title }}</h1>
				<span v-if="caseData.identifier" class="public-case-view__identifier">
					{{ caseData.identifier }}
				</span>
			</header>

			<!-- Status -->
			<section class="public-case-view__section">
				<h2>{{ t('procest', 'Status') }}</h2>
				<span class="public-case-view__status-badge">
					{{ caseData.status || t('procest', 'Unknown') }}
				</span>
			</section>

			<!-- Case details -->
			<section class="public-case-view__section">
				<h2>{{ t('procest', 'Details') }}</h2>
				<dl class="public-case-view__details">
					<div v-if="caseData.startDate">
						<dt>{{ t('procest', 'Start date') }}</dt>
						<dd>{{ formatDate(caseData.startDate) }}</dd>
					</div>
					<div v-if="caseData.plannedEndDate">
						<dt>{{ t('procest', 'Expected completion') }}</dt>
						<dd>{{ formatDate(caseData.plannedEndDate) }}</dd>
					</div>
					<div v-if="caseData.deadline">
						<dt>{{ t('procest', 'Deadline') }}</dt>
						<dd>{{ formatDate(caseData.deadline) }}</dd>
					</div>
				</dl>
			</section>

			<!-- Comment form (if permitted) -->
			<section v-if="canComment" class="public-case-view__section">
				<h2>{{ t('procest', 'Add comment') }}</h2>
				<div class="public-case-view__comment-form">
					<NcTextField
						:value="commentAuthor"
						:label="t('procest', 'Your name or organization')"
						@update:value="v => commentAuthor = v" />
					<textarea
						v-model="commentText"
						class="public-case-view__textarea"
						:placeholder="t('procest', 'Write your comment...')"
						rows="4" />
					<NcButton type="primary" :disabled="!commentText.trim()" @click="submitComment">
						{{ t('procest', 'Submit comment') }}
					</NcButton>
				</div>
			</section>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'PublicCaseView',
	components: {
		NcButton,
		NcTextField,
		NcLoadingIcon,
	},
	props: {
		token: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: true,
			error: '',
			caseData: null,
			canComment: false,
			canUpload: false,
			requiresPassword: false,
			password: '',
			passwordError: '',
			commentAuthor: '',
			commentText: '',
		}
	},
	mounted() {
		this.loadShareData()
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		async loadShareData() {
			this.loading = true
			this.error = ''
			try {
				const url = `/apps/procest/api/public/share/${this.token}`
				const params = this.password ? `?password=${encodeURIComponent(this.password)}` : ''
				const response = await fetch(url + params)
				const data = await response.json()

				if (data.requiresPassword) {
					this.requiresPassword = true
				} else if (!data.success) {
					this.error = data.error || t('procest', 'Access denied')
				} else {
					this.caseData = data.case
					this.canComment = data.canComment
					this.canUpload = data.canUpload
					this.requiresPassword = false
				}
			} catch (err) {
				this.error = t('procest', 'Could not load case data')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		async submitPassword() {
			this.passwordError = ''
			await this.loadShareData()
			if (this.requiresPassword) {
				this.passwordError = t('procest', 'Incorrect password')
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		async submitComment() {
			try {
				const response = await fetch(`/apps/procest/api/public/share/${this.token}/comment`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						comment: this.commentText,
						authorName: this.commentAuthor,
					}),
				})
				const data = await response.json()
				if (data.success) {
					this.commentText = ''
				}
			} catch (err) {
				// Comment submission failed silently
			}
		},
		/**
		 * @param dateString
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		formatDate(dateString) {
			if (!dateString) return ''
			return new Date(dateString).toLocaleDateString('nl-NL', {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.public-case-view {
	max-width: 800px;
	margin: 0 auto;
	padding: 24px;
}

.public-case-view__loading {
	text-align: center;
	padding: 48px;
}

.public-case-view__header {
	margin-bottom: 24px;
}

.public-case-view__header h1 {
	margin: 0 0 4px;
}

.public-case-view__identifier {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.public-case-view__section {
	margin-bottom: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.public-case-view__status-badge {
	display: inline-block;
	padding: 4px 12px;
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	font-weight: bold;
}

.public-case-view__details {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.public-case-view__details dt {
	font-weight: bold;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.public-case-view__password {
	max-width: 400px;
	margin: 48px auto;
	text-align: center;
}

.public-case-view__password-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 16px;
}

.public-case-view__error,
.public-case-view__error-page {
	color: var(--color-error);
	text-align: center;
	padding: 48px;
}

.public-case-view__textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	resize: vertical;
}

.public-case-view__comment-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>
