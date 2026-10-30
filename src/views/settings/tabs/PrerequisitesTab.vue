<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  What dossiq needs, and what this instance has: three tables (platform,
  PHP extensions, apps) with what each does and whether it is here, as board
  DqBeheerGeavanceerd draws them.

  Every row is read on the server at page load, from `Prerequisites::check()`.
  Nothing here asks the browser anything: a PHP extension is not visible from
  a browser, and an app the reader cannot see is not the same as an app that
  is not installed.

  A missing prerequisite is REPORTED and never silently worked around
  (ADR-102). Only Open Register blocks; everything else says what it would
  have added, so an administrator can decide whether they want it.

  @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
-->
<template>
	<div class="prerequisites" data-testid="prerequisites-block">
		<NcNoteCard
			v-if="missingRequired.length > 0"
			type="error"
			data-testid="prerequisites-blocking">
			{{ t('dossiq', 'Dossiq cannot run until this is installed:') }}
			{{ missingRequired.map((row) => row.name || row.id).join(', ') }}
		</NcNoteCard>

		<table class="prerequisites__table" data-testid="prerequisites-platform">
			<thead>
				<tr>
					<th scope="col">
						{{ t('dossiq', 'Platform') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'What it does') }}
					</th>
					<th scope="col" class="prerequisites__here">
						{{ t('dossiq', 'Here') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr data-testid="prerequisite-php">
					<th scope="row">
						{{ t('dossiq', 'PHP {version} or higher', { version: php.required }) }}
					</th>
					<td>{{ t('dossiq', 'This instance runs {version}', { version: php.running }) }}</td>
					<td><span :class="markClass(php.present)">{{ mark(php.present) }}</span></td>
				</tr>
				<tr data-testid="prerequisite-nextcloud">
					<th scope="row">
						{{ t('dossiq', 'Nextcloud {min} to {max}', { min: nextcloud.min, max: nextcloud.max }) }}
					</th>
					<td>
						<template v-if="nextcloud.running">
							{{ t('dossiq', 'This instance runs {version}', { version: nextcloud.running }) }}
						</template>
					</td>
					<td><span :class="markClass(nextcloud.present)">{{ mark(nextcloud.present) }}</span></td>
				</tr>
			</tbody>
		</table>

		<table class="prerequisites__table" data-testid="prerequisites-extensions">
			<thead>
				<tr>
					<th scope="col">
						{{ t('dossiq', 'PHP extension') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'What it does') }}
					</th>
					<th scope="col" class="prerequisites__here">
						{{ t('dossiq', 'Here') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in extensions"
					:key="row.name"
					:data-testid="`prerequisite-ext-${row.name}`">
					<th scope="row">
						{{ row.name }}
					</th>
					<td>{{ t('dossiq', row.why) }}</td>
					<td><span :class="markClass(row.present)">{{ mark(row.present) }}</span></td>
				</tr>
			</tbody>
		</table>

		<table class="prerequisites__table" data-testid="prerequisites-apps">
			<thead>
				<tr>
					<th scope="col">
						{{ t('dossiq', 'App') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'What it does') }}
					</th>
					<th scope="col" class="prerequisites__here">
						{{ t('dossiq', 'Here') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in required"
					:key="row.id"
					:data-testid="`prerequisite-app-${row.id}`">
					<th scope="row">
						{{ row.name || row.id }}
					</th>
					<td>{{ t('dossiq', row.unlocks) }}</td>
					<td><span :class="markClass(row.present)">{{ mark(row.present) }}</span></td>
				</tr>
			</tbody>
			<tbody>
				<tr
					v-for="row in optional"
					:key="row.id"
					:data-testid="`prerequisite-app-${row.id}`">
					<th scope="row">
						{{ row.name || row.id }}
					</th>
					<td>{{ t('dossiq', row.unlocks) }}</td>
					<td><span :class="markClass(row.present)">{{ mark(row.present) }}</span></td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

export default {
	name: 'PrerequisitesTab',

	components: { NcNoteCard },

	data() {
		// An absent state renders an empty block rather than throwing. The
		// settings page carries a dozen other sections, and one of them
		// failing to read its state should not take the rest of the page.
		const state = loadState('dossiq', 'prerequisites', {})

		return {
			php: state.php ?? { required: '', running: '', present: false },
			nextcloud: state.nextcloud ?? {
				min: '',
				max: '',
				running: '',
				present: false,
			},

			extensions: state.extensions ?? [],
			required: state.apps?.required ?? [],
			optional: state.apps?.optional ?? [],
		}
	},

	computed: {
		/**
		 * @return {Array} The required apps this instance does not have.
		 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		missingRequired() {
			return this.required.filter((row) => row.present !== true)
		},
	},

	methods: {
		t,

		/**
		 * The word beside a row, which is what a screen reader announces.
		 *
		 * Text and not a colour: "present" and "missing" are the whole
		 * message, and a green dot alone fails WCAG 1.4.1.
		 *
		 * @param {boolean} present Whether the item is there.
		 * @return {string} The word.
		 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
		 */
		mark(present) {
			return present === true ? t('dossiq', 'present') : t('dossiq', 'missing')
		},

		/**
		 * The class carrying the colour that repeats the word.
		 *
		 * @param {boolean} present Whether the item is there.
		 * @return {string} The class.
		 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
		 */
		markClass(present) {
			return present === true
				? 'prerequisites__mark cn-status-badge cn-status-badge--success'
				: 'prerequisites__mark cn-status-badge cn-status-badge--default prerequisites__mark--missing'
		},
	},
}
</script>

<style scoped>
.prerequisites {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.prerequisites__table th[scope='row'] {
	font-weight: 600;
	text-align: start;
}

.prerequisites__table td:nth-child(2)::first-letter {
	text-transform: uppercase;
}

.prerequisites__here {
	inline-size: 120px;
}

/*
 * Fill and ink in matching pairs. Since Nextcloud 32 `--color-success` and
 * `--color-error` are light FILLS; used as ink they were near-invisible on the
 * page background (round-4 cloud check). Each fill has a `-text` partner
 * that meets WCAG AA on it, in the light and the dark theme.
 */
.prerequisites__mark {
	display: inline-block;
}

.prerequisites__mark::first-letter {
	text-transform: uppercase;
}

.prerequisites .prerequisites__mark--missing {
	background: var(--color-error);
	--cn-status-badge-ink: var(--color-error-text);
	color: var(--cn-status-badge-ink);
}
</style>
