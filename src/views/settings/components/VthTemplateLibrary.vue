<template>
	<div class="vth-template-library">
		<h3>{{ t('procest', 'VTH Workflow Templates') }}</h3>
		<p class="vth-template-library__description">
			{{ t('procest', 'Pre-built workflow templates for VTH (Vergunningen, Toezicht, Handhaving) processes. Select a template to preview and import.') }}
		</p>

		<div class="vth-template-library__grid">
			<div
				v-for="template in templates"
				:key="template.slug"
				class="vth-template-card"
				:class="{ 'vth-template-card--selected': selectedTemplate?.slug === template.slug }"
				role="button"
				tabindex="0"
				@click="selectTemplate(template)"
				@keydown.enter="selectTemplate(template)"
				@keydown.space.prevent="selectTemplate(template)">
				<div class="vth-template-card__header">
					<span class="vth-template-card__icon">
						<NcIconSvgWrapper :svg="getIcon(template.category)" :size="24" />
					</span>
					<strong class="vth-template-card__title">{{ template.title }}</strong>
				</div>
				<p class="vth-template-card__description">
					{{ template.description }}
				</p>
				<div class="vth-template-card__meta">
					<span class="vth-template-card__badge">
						{{ t('procest', '{count} steps', { count: template.stepCount }) }}
					</span>
					<span v-if="template.processingTime" class="vth-template-card__badge">
						{{ template.processingTime }}
					</span>
					<span class="vth-template-card__badge vth-template-card__badge--category">
						{{ template.category }}
					</span>
				</div>
			</div>
		</div>

		<!-- Preview panel -->
		<div v-if="selectedTemplate" class="vth-template-library__preview">
			<h4>{{ selectedTemplate.title }}</h4>
			<p>{{ selectedTemplate.data.description }}</p>

			<div class="vth-template-library__preview-steps">
				<h5>{{ t('procest', 'Workflow Steps') }}</h5>
				<ol>
					<li v-for="step in selectedTemplate.data.steps" :key="step.id">
						<strong>{{ step.label }}</strong>
						<span v-if="step.isInitial" class="step-badge step-badge--initial">
							{{ t('procest', 'Start') }}
						</span>
						<span v-if="step.isFinal" class="step-badge step-badge--final">
							{{ t('procest', 'End') }}
						</span>
						<br>
						<small>{{ step.description }}</small>
					</li>
				</ol>
			</div>

			<div class="vth-template-library__preview-manifest">
				<h5>{{ t('procest', 'Required Configuration') }}</h5>
				<p>
					<strong>{{ t('procest', 'Status types:') }}</strong>
					{{ selectedTemplate.data.manifest.statusTypes.join(', ') }}
				</p>
				<p>
					<strong>{{ t('procest', 'Role types:') }}</strong>
					{{ selectedTemplate.data.manifest.roleTypes.join(', ') }}
				</p>
			</div>

			<div class="vth-template-library__actions">
				<NcButton type="primary" @click="$emit('import', selectedTemplate.data)">
					{{ t('procest', 'Import this template') }}
				</NcButton>
				<NcButton @click="selectedTemplate = null">
					{{ t('procest', 'Back to list') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

// Import VTH workflow template JSON files
import omgevingsvergunningRegulair from '../../../../lib/Settings/vth-templates/omgevingsvergunning-regulier.json'
import omgevingsvergunningUitgebreid from '../../../../lib/Settings/vth-templates/omgevingsvergunning-uitgebreid.json'
import toezichtzaakBouw from '../../../../lib/Settings/vth-templates/toezichtzaak-bouw.json'
import toezichtzaakMilieu from '../../../../lib/Settings/vth-templates/toezichtzaak-milieu.json'
import handhavingszaak from '../../../../lib/Settings/vth-templates/handhavingszaak.json'
import sloopmelding from '../../../../lib/Settings/vth-templates/sloopmelding.json'

export default {
	name: 'VthTemplateLibrary',

	components: {
		NcButton,
		NcIconSvgWrapper,
	},

	emits: ['import'],

	data() {
		return {
			selectedTemplate: null,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-vth-workflow-templates/tasks.md */
		templates() {
			return [
				{
					slug: 'omgevingsvergunning-regulier',
					title: t('procest', 'Omgevingsvergunning (regulier)'),
					description: t('procest', 'Permit application for building activities — 8 week standard procedure'),
					category: t('procest', 'Permits'),
					stepCount: omgevingsvergunningRegulair.steps.length,
					processingTime: t('procest', '8 weeks'),
					data: omgevingsvergunningRegulair,
				},
				{
					slug: 'omgevingsvergunning-uitgebreid',
					title: t('procest', 'Omgevingsvergunning (uitgebreid)'),
					description: t('procest', 'Extended permit procedure with public consultation — 26 week procedure'),
					category: t('procest', 'Permits'),
					stepCount: omgevingsvergunningUitgebreid.steps.length,
					processingTime: t('procest', '26 weeks'),
					data: omgevingsvergunningUitgebreid,
				},
				{
					slug: 'sloopmelding',
					title: t('procest', 'Demolition notification'),
					description: t('procest', 'Demolition notification — 4 week assessment period'),
					category: t('procest', 'Permits'),
					stepCount: sloopmelding.steps.length,
					processingTime: t('procest', '4 weeks'),
					data: sloopmelding,
				},
				{
					slug: 'toezichtzaak-bouw',
					title: t('procest', 'Construction supervision case'),
					description: t('procest', 'Building supervision with three inspection phases: foundation, shell, completion'),
					category: t('procest', 'Supervision'),
					stepCount: toezichtzaakBouw.steps.length,
					processingTime: null,
					data: toezichtzaakBouw,
				},
				{
					slug: 'toezichtzaak-milieu',
					title: t('procest', 'Environmental supervision case'),
					description: t('procest', 'Environmental supervision — periodic or incident-based inspections'),
					category: t('procest', 'Supervision'),
					stepCount: toezichtzaakMilieu.steps.length,
					processingTime: null,
					data: toezichtzaakMilieu,
				},
				{
					slug: 'handhavingszaak',
					title: t('procest', 'Enforcement case'),
					description: t('procest', 'Enforcement case following LHS national strategy — includes penalty and re-inspection cycles'),
					category: t('procest', 'Enforcement'),
					stepCount: handhavingszaak.steps.length,
					processingTime: null,
					data: handhavingszaak,
				},
			]
		},
	},

	methods: {
		t,

		/**
		 * @param template
		 * @spec openspec/changes/retrofit-2026-05-25-vth-workflow-templates/tasks.md
		 */
		selectTemplate(template) {
			this.selectedTemplate = template
		},

		/**
		 * @param category
		 * @spec openspec/changes/retrofit-2026-05-25-vth-workflow-templates/tasks.md
		 */
		getIcon(category) {
			// Return simple SVG path based on category
			const icons = {
				Vergunningen: '<svg viewBox="0 0 24 24"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z" fill="currentColor"/></svg>',
				Toezicht: '<svg viewBox="0 0 24 24"><path d="M12,4.5C7,4.5 2.73,7.61 1,12C2.73,16.39 7,19.5 12,19.5C17,19.5 21.27,16.39 23,12C21.27,7.61 17,4.5 12,4.5M12,17A5,5 0 0,1 7,12A5,5 0 0,1 12,7A5,5 0 0,1 17,12A5,5 0 0,1 12,17M12,9A3,3 0 0,0 9,12A3,3 0 0,0 12,15A3,3 0 0,0 15,12A3,3 0 0,0 12,9Z" fill="currentColor"/></svg>',
				Handhaving: '<svg viewBox="0 0 24 24"><path d="M12,1L3,5V11C3,16.55 6.84,21.74 12,23C17.16,21.74 21,16.55 21,11V5L12,1M12,5A3,3 0 0,1 15,8A3,3 0 0,1 12,11A3,3 0 0,1 9,8A3,3 0 0,1 12,5M17.13,17C15.92,18.85 14.11,20.24 12,20.92C9.89,20.24 8.08,18.85 6.87,17C6.53,16.5 6.24,16 6,15.47C6,13.82 8.71,12.47 12,12.47C15.29,12.47 18,13.79 18,15.47C17.76,16 17.47,16.5 17.13,17Z" fill="currentColor"/></svg>',
			}
			return icons[category] || icons.Vergunningen
		},
	},
}
</script>

<style scoped>
.vth-template-library {
	padding: 16px 0;
}

.vth-template-library__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.vth-template-library__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 12px;
	margin-bottom: 24px;
}

.vth-template-card {
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	cursor: pointer;
	transition: border-color 0.15s, box-shadow 0.15s;
}

.vth-template-card:hover {
	border-color: var(--color-primary-element);
}

.vth-template-card--selected {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 1px var(--color-primary-element);
}

.vth-template-card__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.vth-template-card__title {
	font-size: 14px;
}

.vth-template-card__description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.vth-template-card__meta {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.vth-template-card__badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.vth-template-card__badge--category {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.vth-template-library__preview {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-top: 16px;
}

.vth-template-library__preview-steps ol {
	padding-left: 20px;
}

.vth-template-library__preview-steps li {
	margin-bottom: 8px;
}

.step-badge {
	font-size: 10px;
	padding: 1px 6px;
	border-radius: 8px;
	margin-left: 6px;
}

.step-badge--initial {
	background: var(--color-success);
	color: white;
}

.step-badge--final {
	background: var(--color-error);
	color: white;
}

.vth-template-library__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}

@media (prefers-reduced-motion: reduce) {
	.vth-template-card {
		transition: none;
	}
}
</style>
