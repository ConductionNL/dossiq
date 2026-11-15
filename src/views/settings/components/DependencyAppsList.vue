<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The apps dossiq needs and the apps it works with, each by its product name,
  with the app id it was looked up as and whether this instance has it. The
  rows come from the admin page's `dependencyApps` initial state.

  @spec openspec/specs/admin-settings/spec.md
-->
<template>
	<ul class="dependency-apps" data-testid="dependency-apps">
		<li
			v-for="app in apps"
			:key="app.name"
			class="dependency-apps__row">
			<span class="dependency-apps__name">
				<strong>{{ app.name }}</strong>
				<span class="dependency-apps__id">
					{{ t('dossiq', 'looked up as {appId}', { appId: app.appId }) }}
				</span>
			</span>
			<span
				class="cn-status-badge"
				:class="app.present ? 'cn-status-badge--success' : 'cn-status-badge--default'">
				{{ app.present ? t('dossiq', 'Present') : t('dossiq', 'Not installed') }}
			</span>
		</li>
	</ul>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'DependencyAppsList',

	data() {
		return {
			apps: loadState('dossiq', 'dependencyApps', []),
		}
	},
}
</script>

<style scoped>
.dependency-apps {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 0 32px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.dependency-apps__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 12px;
	padding: 8px 0;
	border-top: 1px solid var(--color-border);
	font-size: 14px;
}

.dependency-apps__name {
	display: flex;
	flex: 1 1 200px;
	flex-direction: column;
	gap: 1px;
}

.dependency-apps__name strong {
	font-weight: 600;
}

.dependency-apps__id {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
