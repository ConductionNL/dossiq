<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The cards a case type declares for its Overview tab (`overviewSections`).

	A case type is configuration, so a complaint, a tender and a grant get their
	own cards without a component of their own: each section names a title and
	rows, and a row reads a field of the case. Text on the rows comes from the
	case type as an organisation wrote it.

	@spec openspec/specs/case-page/spec.md
-->
<template>
	<div v-if="sections.length > 0" class="case-overview-sections" data-testid="case-overview-sections">
		<section v-for="section in sections"
			:key="section.id"
			class="case-overview-sections__card"
			:aria-labelledby="`case-section-${section.id}`"
			data-testid="case-overview-section">
			<div class="case-overview-sections__head">
				<h3 :id="`case-section-${section.id}`">
					{{ section.title }}
				</h3>
				<span v-if="section.pill" class="case-overview-sections__pill">{{ section.pill }}</span>
			</div>
			<p v-if="section.intro" class="case-overview-sections__note">
				{{ section.intro }}
			</p>
			<dl v-if="section.rows.length > 0"
				class="case-overview-sections__rows"
				:class="{ 'case-overview-sections__rows--two': section.columns === 2 }">
				<div v-for="row in section.rows" :key="row.label">
					<dt>{{ row.label }}</dt>
					<dd>{{ row.value }}</dd>
				</div>
			</dl>
			<p v-if="section.note" class="case-overview-sections__note">
				{{ section.note }}
			</p>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { resolveOverviewSections } from '../../../utils/caseOverviewSections.js'

export default {
	name: 'CaseOverviewSections',

	props: {
		/** The case, as the detail page loaded it. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return { declared: [] }
	},

	computed: {
		/**
		 * @return {Array<object>} The sections with their rows filled from the case.
		 *
		 * @spec openspec/specs/case-page/spec.md#requirement-a-case-type-declares-its-overview-cards
		 */
		sections() {
			return resolveOverviewSections(this.declared, this.objectData)
		},
	},

	watch: {
		'objectData.caseType': {
			immediate: true,
			/**
			 * Reload the sections when the case's type changes.
			 *
			 * @spec openspec/specs/case-page/spec.md
			 * @return {void}
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Read the sections of the case's type. A case type that cannot be read
		 * draws no sections; the page gates this widget on the same list.
		 *
		 * @return {Promise<void>} Resolves when the sections are known.
		 *
		 * @spec openspec/specs/case-page/spec.md#requirement-a-case-type-declares-its-overview-cards
		 */
		async load() {
			const caseType = this.objectData?.caseType
			const id = typeof caseType === 'object' ? caseType?.id : caseType
			if (!id) {
				this.declared = []
				return
			}

			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/dossiq/caseType/' + encodeURIComponent(id)),
				)
				this.declared = data?.overviewSections || []
			} catch {
				this.declared = []
			}
		},
	},
}
</script>

<style scoped>
.case-overview-sections {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.case-overview-sections__card {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 20px 22px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
}

.case-overview-sections__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.case-overview-sections__head h3 {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 15px;
	font-weight: 600;
	line-height: 1.2;
}

.case-overview-sections__pill {
	padding: 2px 10px;
	border-radius: 12px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 13px;
	font-weight: 600;
}

.case-overview-sections__rows {
	display: grid;
	grid-template-columns: 1fr;
	gap: 10px 24px;
	margin: 0;
}

.case-overview-sections__rows--two {
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.case-overview-sections__rows > div {
	display: grid;
	grid-template-columns: minmax(90px, auto) 1fr;
	gap: 14px;
}

.case-overview-sections__rows dt {
	color: var(--color-text-maxcontrast);
}

.case-overview-sections__rows dd {
	margin: 0;
	font-weight: 600;
}

.case-overview-sections__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
