<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - What dossiq shipped, what this instance changed, and what it authored. One
  - row per seeded object, with the set and version it came from, and the newer
  - version offered per object rather than for the set as a whole. Rendered as a
  - tab inside AdminRoot's CnSettingsSection, so it carries no NcSettingsSection
  - wrapper of its own.
  -
  - @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
  -
  - @visual exclude Admin-only panel whose table is empty until a seed has run against a live OpenRegister register, so a screenshot baseline would capture the empty state. The state derivation (shippedLabel, hasUpdate, adoptionLosesLocalChange) is unit-tested in tests/vitest/shippedConfiguration.spec.js.
-->
<template>
	<div class="shipped-configuration">
		<NcSelect
			class="shipped-configuration__picker"
			:modelValue="schemaOption"
			:options="schemas"
			:inputLabel="t('dossiq', 'Show')"
			:clearable="false"
			data-testid="shipped-schema"
			@update:modelValue="pick" />

		<table
			class="shipped-configuration__table"
			data-testid="shipped-configuration-table">
			<thead>
				<tr>
					<th scope="col">{{ t('dossiq', 'Name') }}</th>
					<th scope="col">{{ t('dossiq', 'State') }}</th>
					<th scope="col">{{ t('dossiq', 'Shipped set') }}</th>
					<th scope="col">{{ t('dossiq', 'Version') }}</th>
					<th scope="col">{{ t('dossiq', 'Newer version') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.targetObject">
					<td class="shipped-configuration__name">{{ row.title || row.targetObject }}</td>
					<td :data-testid="'shipped-state-' + row.targetObject">
						<span class="cn-status-badge" :class="'cn-status-badge--' + stateTone(row)">
							{{ label(row) }}
						</span>
					</td>
					<td>{{ row.set }}</td>
					<td>{{ row.setVersion }}</td>
					<td>
						<NcButton
							v-if="updatable(row)"
							:data-testid="'shipped-adopt-' + row.targetObject"
							@click="adopt(row)">
							{{ adoptLabel(row) }}
						</NcButton>
						<span v-else class="shipped-configuration__uptodate">
							{{ t('dossiq', 'Up to date') }}
						</span>
					</td>
				</tr>
				<tr v-if="!rows.length">
					<td
						colspan="5"
						class="shipped-configuration__empty"
						data-testid="shipped-configuration-empty">
						{{ emptyText }}
					</td>
				</tr>
			</tbody>
		</table>

		<p v-if="loadError" class="shipped-configuration__error">
			{{ loadError }}
		</p>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcSelect } from '@nextcloud/vue'
import {
	adoptShipped,
	countObjects,
	listShipped,
} from '../../services/starterApi.js'
import {
	adoptionLosesLocalChange,
	hasUpdate,
	shippedLabel,
} from '../../utils/starterStates.js'

export default {
	name: 'ShippedConfiguration',
	components: { NcButton, NcSelect },
	data() {
		return {
			schema: 'caseType',
			rows: [],
			present: null,
			loadError: '',
			schemas: [
				{ slug: 'caseType', label: t('dossiq', 'Case types') },
				{ slug: 'roleType', label: t('dossiq', 'Roles') },
				{ slug: 'statusType', label: t('dossiq', 'Statuses') },
				{ slug: 'resultType', label: t('dossiq', 'Results') },
			],
		}
	},

	computed: {
		/**
		 * The picked schema as its option, for the select.
		 *
		 * @return {object} The option.
		 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
		 */
		schemaOption() {
			return this.schemas.find((option) => option.slug === this.schema) || this.schemas[0]
		},

		/**
		 * What the empty table says, which depends on what the register holds.
		 *
		 * The ledger only records what a starter set seeded. Objects that came
		 * with the register import are not in it, so "nothing seeded" beside
		 * 24 case types was untrue (round-4 cloud check).
		 *
		 * @return {string} The sentence.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		emptyText() {
			if (typeof this.present === 'number' && this.present > 0) {
				return t(
					'dossiq',
					'The register holds {count} of these. None came from a starter set, so there is no shipped version to compare with.',
					{ count: this.present },
				)
			}

			return t('dossiq', 'Nothing has been seeded yet.')
		},
	},

	mounted() {
		this.reload()
	},

	methods: {
		/**
		 * Show what shipped for another schema.
		 *
		 * @param {object|null} option The picked option.
		 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
		 */
		pick(option) {
			if (option && option.slug !== this.schema) {
				this.schema = option.slug
				this.reload()
			}
		},

		/**
		 * Read what shipped for the selected schema.
		 *
		 * @spec exclude presentational reload helper, no business logic
		 */
		async reload() {
			try {
				const data = await listShipped(this.schema)
				this.rows = Array.isArray(data.items) ? data.items : []
				this.loadError = ''
				this.present = null
				if (this.rows.length === 0) {
					this.present = await countObjects(this.schema)
				}
			} catch {
				// The list is emptied on purpose. Leaving the previous rows on
				// screen beside an error message would read as the current
				// state of the register, which is exactly what it is not.
				this.rows = []
				this.present = null
				this.loadError = t(
					'dossiq',
					'Could not read the shipped configuration',
				)
				showError(this.loadError)
			}
		},

		/**
		 * What an administrator reads beside one object.
		 *
		 * @param {object} row The row.
		 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
		 */
		/**
		 * The status badge tone of a row's state: shipped and untouched is
		 * green, changed here or removed is amber, the rest neutral.
		 *
		 * @param {object} row The row.
		 * @return {string} The tone.
		 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
		 */
		stateTone(row) {
			return { shipped: 'success', changed: 'warning', removed: 'warning' }[row.state] || 'default'
		},

		label(row) {
			return shippedLabel(row)
		},

		/**
		 * Whether a newer shipped version is waiting for this object.
		 *
		 * @param {object} row The row.
		 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
		 */
		updatable(row) {
			return hasUpdate(row)
		},

		/**
		 * The button's words, which say plainly when a local change goes.
		 *
		 * @param {object} row The row.
		 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
		 */
		adoptLabel(row) {
			return adoptionLosesLocalChange(row)
				? t('dossiq', 'Take {version} and lose your change', {
						version: row.latestVersion,
					})
				: t('dossiq', 'Take {version}', { version: row.latestVersion })
		},

		/**
		 * Take the newer shipped version of one object.
		 *
		 * @param {object} row The row.
		 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
		 */
		async adopt(row) {
			try {
				await adoptShipped(this.schema, row.targetObject, {
					set: row.set,
					acceptLocalChangeLoss: adoptionLosesLocalChange(row),
				})
				showSuccess(t('dossiq', 'The newer version was taken'))
				await this.reload()
			} catch (e) {
				showError(
					e.response?.data?.reason
						|| t('dossiq', 'The newer version was not taken'),
				)
			}
		},
	},
}
</script>

<style scoped>
.shipped-configuration__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 16px;
	/* Nextcloud's own stylesheet sets `table { white-space: nowrap }`. Under
	   it the one-cell "the register holds 47, none of them shipped" sentence
	   ran 818px wide in a 779px section at a 1100px window and pushed the
	   admin page sideways. A cell here is a name or a sentence; let it wrap. */
	white-space: normal;
}

.shipped-configuration__table th,
.shipped-configuration__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

/* The picker reads as a settings field, label above and at most 560px wide,
   as board DqBeheerGeavanceerd "Wat dossiq meelevert" draws it. */
.shipped-configuration__picker {
	display: block;
	width: 100%;
	max-width: 560px;
	margin-block-end: 4px;
}

.shipped-configuration__name {
	font-weight: 600;
}

.shipped-configuration__empty,
.shipped-configuration__uptodate {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.shipped-configuration__error {
	color: var(--color-error-text);
	margin-top: 12px;
}
</style>
