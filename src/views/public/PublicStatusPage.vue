<template>
	<div class="public-status-page">
		<div v-if="loading" class="public-status-page__loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('procest', 'Loading status...') }}</p>
		</div>

		<div v-else-if="error" class="public-status-page__error">
			<h2>{{ t('procest', 'Status unavailable') }}</h2>
			<p>{{ error }}</p>
		</div>

		<div v-else-if="statusData" class="public-status-page__content">
			<header class="public-status-page__header">
				<h1>{{ statusData.title }}</h1>
				<p v-if="statusData.identifier" class="public-status-page__ref">
					{{ t('procest', 'Reference: {ref}', { ref: statusData.identifier }) }}
				</p>
			</header>

			<!-- Visual status indicator -->
			<section class="public-status-page__progress" role="progressbar" :aria-label="t('procest', 'Case progress')">
				<div class="public-status-page__status-label">
					{{ t('procest', 'Current status') }}
				</div>
				<div class="public-status-page__status-value">
					{{ statusData.currentStatus || t('procest', 'In progress') }}
				</div>
			</section>

			<!-- Dates -->
			<section class="public-status-page__dates">
				<div v-if="statusData.startDate" class="public-status-page__date-item">
					<span class="public-status-page__date-label">{{ t('procest', 'Submitted') }}</span>
					<span class="public-status-page__date-value">{{ formatDate(statusData.startDate) }}</span>
				</div>
				<div v-if="statusData.plannedEndDate" class="public-status-page__date-item">
					<span class="public-status-page__date-label">{{ t('procest', 'Expected completion') }}</span>
					<span class="public-status-page__date-value">{{ formatDate(statusData.plannedEndDate) }}</span>
				</div>
			</section>

			<footer class="public-status-page__footer">
				<p>{{ t('procest', 'For questions about your case, please contact the municipality.') }}</p>
			</footer>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'PublicStatusPage',
	components: {
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
			statusData: null,
		}
	},
	mounted() {
		this.loadStatus()
	},
	methods: {
		async loadStatus() {
			this.loading = true
			try {
				const response = await fetch(`/apps/procest/api/public/status/${this.token}`)
				const data = await response.json()

				if (!data.success) {
					this.error = data.error || t('procest', 'Status unavailable')
				} else {
					this.statusData = data.status
				}
			} catch (err) {
				this.error = t('procest', 'Could not load status')
			} finally {
				this.loading = false
			}
		},
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
.public-status-page {
	max-width: 600px;
	margin: 0 auto;
	padding: 32px 24px;
	font-family: var(--font-face), sans-serif;
}

.public-status-page__loading {
	text-align: center;
	padding: 48px;
}

.public-status-page__error {
	text-align: center;
	padding: 48px;
	color: var(--color-error);
}

.public-status-page__header {
	margin-bottom: 32px;
	text-align: center;
}

.public-status-page__header h1 {
	margin: 0 0 8px;
	font-size: 24px;
}

.public-status-page__ref {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.public-status-page__progress {
	text-align: center;
	padding: 24px;
	margin-bottom: 24px;
	border: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
}

.public-status-page__status-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.public-status-page__status-value {
	font-size: 20px;
	font-weight: bold;
	color: var(--color-primary-element);
}

.public-status-page__dates {
	display: flex;
	gap: 24px;
	justify-content: center;
	margin-bottom: 32px;
}

.public-status-page__date-item {
	text-align: center;
}

.public-status-page__date-label {
	display: block;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.public-status-page__date-value {
	font-weight: bold;
}

.public-status-page__footer {
	text-align: center;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
