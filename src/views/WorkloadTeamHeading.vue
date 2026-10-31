<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<WorkloadSummaryBar part="heading" />
</template>

<script>
import WorkloadSummaryBar from './WorkloadSummaryBar.vue'

/**
 * The team workload's title and hint, under My Work's header (board DqAanMijToegewezen).
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-assigned-to-me-draws-the-teams-name-chips-on-the-toolbar-row
 */
export default {
	name: 'WorkloadTeamHeading',

	components: { WorkloadSummaryBar },
}
</script>
