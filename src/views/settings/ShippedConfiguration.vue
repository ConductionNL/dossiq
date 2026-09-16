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
		<p class="shipped-configuration__intro">
			{{
				t(
					'dossiq',
					'Dossiq ships case types, statuses, results and roles. This is what arrived, and what you changed since.',
				)
			}}
		</p>

		<label class="shipped-configuration__picker" for="shipped-schema">
			{{ t('dossiq', 'Show') }}
		</label>
		<select
			id="shipped-schema"
			v-model="schema"
			data-testid="shipped-schema"
			@change="reload">
			<option
				v-for="option in schemas"
				:key="option.slug"
				:value="option.slug">
				{{ option.label }}
			</option>
		</select>

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
					<td>{{ row.title || row.targetObject }}</td>
					<td :data-testid="'shipped-state-' + row.targetObject">
						{{ label(row) }}
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
					<td colspan="5" class="shipped-configuration__empty">
						{{ t('dossiq', 'Nothing has been seeded yet.') }}
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
import { NcButton } from '@nextcloud/vue'
import { adoptShipped, listShipped } from '../../services/starterApi.js'
import {
	adoptionLosesLocalChange,
	hasUpdate,
	shippedLabel,
} from '../../utils/starterStates.js'

export default {
	name: 'ShippedConfiguration',
	components: { NcButton },
	data() {
		return {
			schema: 'caseType',
			rows: [],
			loadError: '',
			schemas: [
				{ slug: 'caseType', label: t('dossiq', 'Case types') },
				{ slug: 'roleType', label: t('dossiq', 'Roles') },
				{ slug: 'statusType', label: t('dossiq', 'Statuses') },
				{ slug: 'resultType', label: t('dossiq', 'Results') },
			],
		}
	},

	mounted() {
		this.reload()
	},

	methods: {
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
			} catch {
				// The list is emptied on purpose. Leaving the previous rows on
				// screen beside an error message would read as the current
				// state of the register, which is exactly what it is not.
				this.rows = []
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
	margin-top: 12px;
}

.shipped-configuration__table th,
.shipped-configuration__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.shipped-configuration__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.shipped-configuration__picker {
	margin-inline-end: 8px;
}

.shipped-configuration__empty,
.shipped-configuration__uptodate {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.shipped-configuration__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>
