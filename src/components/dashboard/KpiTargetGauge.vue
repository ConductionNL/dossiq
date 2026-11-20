<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="kpi-target-gauge" data-testid="kpi-target-gauge">
		<p v-if="loading" class="kpi-target-gauge__state">
			{{ t('dossiq', 'Loading…') }}
		</p>
		<p v-else-if="failed" class="kpi-target-gauge__state" role="alert">
			{{ t('dossiq', 'This figure could not be loaded') }}
		</p>
		<p v-else-if="!reading" class="kpi-target-gauge__state">
			{{ t('dossiq', 'This figure is not available to you') }}
		</p>
		<template v-else>
			<div
				class="kpi-target-gauge__track"
				role="meter"
				:aria-label="label"
				aria-valuemin="0"
				:aria-valuemax="axisMax"
				:aria-valuenow="reading.value"
				:aria-valuetext="words">
				<div
					class="kpi-target-gauge__fill"
					:class="reading.met ? 'kpi-target-gauge__fill--met' : 'kpi-target-gauge__fill--short'"
					:style="{ width: reading.fill + '%' }" />
				<div
					class="kpi-target-gauge__mark"
					data-testid="kpi-target-gauge-mark"
					:style="{ left: reading.mark + '%' }" />
			</div>
			<p class="kpi-target-gauge__words" data-testid="kpi-target-gauge-words">
				{{ words }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { gaugeReading } from '../../utils/kpiGauge.js'

/**
 * A KPI as a gauge: the value against its target, drawn as a bar with a
 * target mark and said in words ("80% of target 90%"). A reader adds it to
 * the dashboard from the Add widget presets (REQ-CRS-35, row 10.1).
 *
 * Content: `{ label, endpoint, valueField, target, max, invert, format: { decimals, suffix } }`.
 * `endpoint` is an app path (`/apps/dossiq/api/dashboard/kpis`); `valueField`
 * names the figure in its answer. A figure the answer leaves out (the endpoint
 * omits a figure the reader's role may not see) reads "not available", never 0.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
 */
export default {
	name: 'KpiTargetGauge',

	props: {
		/** The widget's content blob from the dashboard preset. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return { loading: false, failed: false, value: null }
	},

	computed: {
		/**
		 * The reading, or null while there is no number.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
		 */
		reading() {
			return gaugeReading(this.value, this.content.target, {
				max: this.content.max,
				invert: this.content.invert === true,
				...(this.content.format || {}),
			})
		},

		/**
		 * The bar's upper bound for assistive technology.
		 *
		 * @return {number}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
		 */
		axisMax() {
			const r = this.reading
			const max = Number(this.content.max)
			return Math.max(Number.isFinite(max) ? max : 0, r ? r.value : 0, r ? r.target : 0)
		},

		/**
		 * The value and its target in words.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
		 */
		words() {
			if (!this.reading) {
				return ''
			}
			return t('dossiq', '{value} of target {target}', {
				value: this.reading.valueText,
				target: this.reading.targetText,
			})
		},

		/**
		 * The accessible name of the bar.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
		 */
		label() {
			return typeof this.content.label === 'string' && this.content.label !== ''
				? t('dossiq', this.content.label)
				: t('dossiq', 'Figure against its target')
		},
	},

	watch: {
		'content.endpoint': function() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the figure from its endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
		 */
		async load() {
			const endpoint = this.content.endpoint
			const field = this.content.valueField
			if (typeof endpoint !== 'string' || !endpoint.startsWith('/apps/') || typeof field !== 'string' || field === '') {
				this.value = null
				return
			}
			this.loading = true
			this.failed = false
			try {
				const { data } = await axios.get(generateUrl(endpoint))
				this.value = data && typeof data === 'object' && field in data ? data[field] : null
			} catch {
				this.failed = true
				this.value = null
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.kpi-target-gauge {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 4px;
}

.kpi-target-gauge__track {
	position: relative;
	height: 14px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
}

.kpi-target-gauge__fill {
	height: 100%;
	border-radius: inherit;
}

.kpi-target-gauge__fill--met {
	background-color: var(--color-success);
}

.kpi-target-gauge__fill--short {
	background-color: var(--color-warning);
}

.kpi-target-gauge__mark {
	position: absolute;
	top: -4px;
	bottom: -4px;
	width: 3px;
	margin-left: -1px;
	background-color: var(--color-main-text);
}

.kpi-target-gauge__words {
	margin: 0;
	font-weight: bold;
	color: var(--color-main-text);
}

.kpi-target-gauge__state {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
