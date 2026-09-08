<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  What a case type actually offers: its statuses, its results and its
  attributes, whether it declared them or inherited them.

  WHY THIS IS A CUSTOM WIDGET AND NOT THREE object-lists. An `object-list`
  fetches OpenRegister directly, so the only question it can ask is
  `statusType where caseType = @objectId` — the type's OWN rows. A type that
  derives its lifecycle from a parent has none of those, so three declared
  lists would render three empty tables on a case type that plainly has four
  statuses, with no error anywhere. The merge only exists on the server, in
  CaseTypeResolver, and `/api/case-types/{id}/blueprint` is how a page reads
  it.

  It is placed in the LAYOUT and not inside a tab strip on purpose. A
  `type: "custom"` widget named as a tab CHILD renders nothing and logs
  nothing, because tab children resolve by registry TYPE rather than through
  the page's `widget-<id>` slots. When `case-type-one-authoring-surface`
  brings the tab strip, this either becomes a declared type or stays where
  it is; what it must not do is quietly become an empty panel.

  @spec openspec/specs/case-types/spec.md
  @spec openspec/specs/property-definition-management/spec.md
-->
<template>
	<div class="case-type-blueprint" data-testid="case-type-blueprint">
		<NcLoadingIcon v-if="loading" :size="24" />

		<p v-else-if="error" class="case-type-blueprint__empty">
			{{ t('dossiq', 'This case type could not be read') }}
		</p>

		<template v-else>
			<p
				v-if="parentTitle"
				class="case-type-blueprint__parent"
				data-testid="case-type-parent">
				{{ t('dossiq', 'Inherits from') }}
				<strong>{{ parentTitle }}</strong>
			</p>

			<section
				v-for="section in sections"
				:key="section.id"
				class="case-type-blueprint__section"
				:data-testid="`case-type-${section.id}`">
				<h4 class="case-type-blueprint__heading">
					{{ section.label }}
				</h4>

				<p
					v-if="section.rows.length === 0"
					class="case-type-blueprint__empty">
					{{ section.emptyText }}
				</p>

				<ul v-else class="case-type-blueprint__rows">
					<li
						v-for="row in section.rows"
						:key="row.key"
						class="case-type-blueprint__row">
						<span class="case-type-blueprint__name">{{ row.name }}</span>
						<span
							v-if="row.badge"
							class="case-type-blueprint__badge"
							:data-origin="row.origin"
							:title="row.badgeTitle">
							{{ row.badge }}
						</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { blueprintSections, parentTitleOf } from '../../utils/caseTypeBlueprint.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseTypeBlueprintWidget',

	components: { NcLoadingIcon },

	data() {
		return {
			loading: true,
			error: false,
			blueprint: null,
		}
	},

	computed: {
		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * The three lists, each row carrying its origin badge.
		 *
		 * @return {Array<object>} The sections.
		 */
		sections() {
			// The labels are translated HERE, in literal t() calls, and handed
			// over. A string that lives in the helper and passes through a
			// translate callback is invisible to `tests/l10n/check-l10n.js`,
			// which extracts by finding a literal inside a t() call: it would
			// never reach l10n/en.json, never reach a translator, and render in
			// English to a Dutch reader with every check green.
			return blueprintSections(this.blueprint, {
				statuses: t('dossiq', 'Statuses'),
				results: t('dossiq', 'Results'),
				properties: t('dossiq', 'Attributes'),
				statusesEmpty: t('dossiq', 'This case type has no statuses yet'),
				resultsEmpty: t('dossiq', 'This case type has no results yet'),
				propertiesEmpty: t('dossiq', 'This case type has no attributes yet'),
				inherited: t('dossiq', 'Inherited'),
				inheritedFrom: t('dossiq', 'Inherited from'),
				shared: t('dossiq', 'Shared'),
				sharedTitle: t('dossiq', 'Shared across every case type'),
			})
		},

		/**
		 * The parent this type inherits from, when it has one.
		 *
		 * @return {string} The parent's title, or '' when it stands alone.
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		parentTitle() {
			return parentTitleOf(this.blueprint)
		},
	},

	/**
	 * Read the blueprint once the widget is on the page.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	async mounted() {
		// Saving a status or an attribute anywhere on the page bumps the page
		// refresh signal; the blueprint re-reads rather than the reader
		// reloading the page.
		subscribe(PAGE_REFRESH, this.load)
		await this.load()
	},

	beforeUnmount() {
		unsubscribe(PAGE_REFRESH, this.load)
	},

	methods: {
		t,

		/**
		 * Read the effective blueprint from the server.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/case-types/spec.md
		 */
		async load() {
			if (!this.caseTypeId) {
				this.loading = false
				this.error = true
				return
			}
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case-types/${encodeURIComponent(this.caseTypeId)}/blueprint`,
					),
				)
				this.blueprint = data
				this.error = false
			} catch {
				// A case type nobody can read says so, rather than rendering
				// three empty lists that read as a type with nothing on it.
				this.blueprint = null
				this.error = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.case-type-blueprint {
	height: 100%;
	padding: 8px 12px;
	overflow-y: auto;
}

.case-type-blueprint__parent {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.case-type-blueprint__section {
	margin-bottom: 16px;
}

.case-type-blueprint__heading {
	margin: 0 0 4px;
	font-size: 0.95em;
	font-weight: 600;
}

.case-type-blueprint__rows {
	margin: 0;
	padding: 0;
	list-style: none;
}

.case-type-blueprint__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 2px 0;
}

.case-type-blueprint__badge {
	padding: 0 8px;
	border-radius: var(--border-radius-pill, 100px);
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.case-type-blueprint__empty {
	color: var(--color-text-maxcontrast);
}
</style>
