// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Cell-widget registry for dossiq's manifest-driven index pages.
//
// Each entry is a Vue component, referenced by id from
// `pages[].config.columns[].widget` in src/manifest.json and resolved by
// CnCellRenderer through the `cnCellWidgets` inject that CnAppRoot provides
// from its `cellWidgets` prop. The component receives
// `{ value, row, property, formatted, ...widgetProps }`.
//
// This is the sibling of `formatters.js`, and the difference between the two
// is the reason this file exists: a FORMATTER returns a string, so it can
// shape a value but cannot say anything about it. A cell that has to carry
// STATE — an overdue deadline in the signalering red, a colour or an icon
// beside the text — needs markup, and markup means a component. Reach for a
// formatter first; add an entry here only when the cell has a state to show.

import CaseStateMarkersCell from '../components/cells/CaseStateMarkersCell.vue'
import DeadlineCountdownCell from '../components/cells/DeadlineCountdownCell.vue'
import DwellDaysCell from '../components/cells/DwellDaysCell.vue'
import LensedValueCell from '../components/cells/LensedValueCell.vue'
import PriorityBadgeCell from '../components/cells/PriorityBadgeCell.vue'
import StatusBadgeCell from '../components/cells/StatusBadgeCell.vue'
import UnreadIndicatorCell from '../components/cells/UnreadIndicatorCell.vue'

export default {
	// The Deadline column on the Cases index: days left, days overdue past
	// the deadline, empty when the case has none.
	// @spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md
	deadlineCountdown: DeadlineCountdownCell,

	// The Days in status column on the Cases index: how long the case has been
	// where it is, and a chip when that is longer than the status allows. The
	// number is held on the case, which is what makes the column sortable on
	// the server; the flag beside it is its own fact and is NOT the deadline.
	// @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	dwellDays: DwellDaysCell,

	// The Priority column on the case lists. The column is keyed on
	// `priorityOrder` so the server sorts it by the declared order rather than
	// alphabetically; the cell reads the word back off the row and draws it in
	// the hue the schema declares.
	// @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	priorityBadge: PriorityBadgeCell,

	// The Status column on the Cases index: the status name in the colour its
	// status type carries.
	// @spec openspec/specs/case-types/spec.md
	statusBadge: StatusBadgeCell,

	// The Unread column on the case lists: whether this row changed since the
	// reader last looked. Keyed on `@self.unread`, which OpenRegister attaches
	// per reader on the render path rather than storing, so nothing in dossiq
	// holds a read state of its own.
	// @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	unreadIndicator: UnreadIndicatorCell,

	// lifecycle-acts-on-the-case REQ-LIFE-13 and REQ-LIFE-15: what a row
	// says about itself besides its status. One column, because three that are
	// empty on nine rows in ten push the case title off the screen for nothing.
	// @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	caseStateMarkers: CaseStateMarkersCell,

	// A lens column on the Objects tab: a field of the linked object, resolved
	// by OpenRegister while it renders the link row. It is a component and not
	// a formatter for one reason: an unreadable value arrives as the object
	// `{ "@withheld": true }`, which a formatter would stringify to
	// `[object Object]` and a blank cell would state as "there is no object".
	// @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
	lensedValue: LensedValueCell,
}
