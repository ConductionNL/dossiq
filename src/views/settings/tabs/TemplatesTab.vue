<template>
	<div class="templates-tab">
		<h3 class="templates-tab__title">
			{{ t('procest', 'Case Type Templates') }}
		</h3>
		<p class="templates-tab__description">
			{{
				t(
					'procest',
					'Activate a pre-configured case type template to quickly set up a new case type with statuses, properties, document types, and roles.',
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="templates.length === 0" class="templates-tab__empty">
			{{ t('procest', 'No templates available.') }}
		</div>

		<div v-else class="templates-tab__grid">
			<div
				v-for="template in templates"
				:key="template.id"
				class="templates-tab__card">
				<div class="templates-tab__card-header">
					<h4>{{ template.title }}</h4>
					<span class="templates-tab__badge">{{ template.category }}</span>
				</div>
				<p class="templates-tab__card-description">
					{{ template.description }}
				</p>
				<div class="templates-tab__card-footer">
					<span class="templates-tab__version"
						>v{{ template.version }}</span
					>
					<NcButton
						type="primary"
						:disabled="activating === template.id"
						@click="activate(template.id)">
						<template v-if="activating === template.id">
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('procest', 'Activate') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Success feedback -->
		<div v-if="activationResult" class="templates-tab__result">
			<p>{{ t('procest', 'Template activated successfully!') }}</p>
			<p>
				{{
					t(
						'procest',
						'Case type created with {statuses} statuses, {properties} properties, {documents} document types.',
						{
							statuses: activationResult.statuses.length,
							properties: activationResult.properties.length,
							documents: activationResult.documents.length,
						},
					)
				}}
			</p>
		</div>

		<!-- Error feedback -->
		<p v-if="error" class="templates-tab__error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'TemplatesTab',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	data() {
		return {
			templates: [],
			loading: true,
			activating: null,
			activationResult: null,
			error: '',
		}
	},
	async mounted() {
		await this.loadTemplates()
	},
	methods: {
		/** @spec openspec/specs/template-library/spec.md */
		async loadTemplates() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/procest/api/templates'),
				)
				this.templates = response.data?.results || []
			} catch (err) {
				this.error =
					err.response?.data?.error
					|| this.t('procest', 'Failed to load templates')
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param templateId
		 * @spec openspec/specs/template-library/spec.md
		 */
		async activate(templateId) {
			this.activating = templateId
			this.activationResult = null
			this.error = ''
			try {
				const response = await axios.post(
					generateUrl('/apps/procest/api/templates/{id}/activate', {
						id: templateId,
					}),
				)
				this.activationResult = response.data
			} catch (err) {
				this.error =
					err.response?.data?.error
					|| this.t('procest', 'Failed to activate template')
			} finally {
				this.activating = null
			}
		},
	},
}
</script>

<style scoped>
.templates-tab__title {
	margin-bottom: 4px;
}

.templates-tab__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.templates-tab__empty {
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
}

.templates-tab__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 16px;
}

.templates-tab__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	background: var(--color-main-background);
}

.templates-tab__card-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 8px;
}

.templates-tab__card-header h4 {
	margin: 0;
}

.templates-tab__badge {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.75rem;
	text-transform: uppercase;
}

.templates-tab__card-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
	font-size: 0.875rem;
}

.templates-tab__card-footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.templates-tab__version {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.templates-tab__result {
	margin-top: 16px;
	padding: 12px;
	background: var(--color-success-light, #e8f5e9);
	border-radius: var(--border-radius);
	color: var(--color-success, #2e7d32);
}

.templates-tab__error {
	margin-top: 16px;
	color: var(--color-error);
}
</style>
