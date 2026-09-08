# Case dashboard view

The case page is where a handler works one case. You reach it from the Cases
list, from My work, or from any list that names a case.

![Case dashboard view](/screenshots/case-dashboard-view.png)

## The identity row

The first row under the title says which case you are on: the case number,
the case type, the current status as a badge, the handler it is assigned to,
and how many days are left on the deadline. An overdue case reads in days
overdue, in red. A case with no status yet reads Unknown rather than showing
nothing, because an empty badge and an unset status look the same and only
one of them needs fixing. A case with no deadline shows no countdown at all.

Above that row sits a breadcrumb, Cases followed by this case. The first
crumb takes you back to the list without going through the menu, and it
carries the query the case page had, so a search or a filter on the URL
survives the trip out and back. The last crumb is the page you are on, so it
is not a link.

## The panels

Under the status controls, one strip of tabs holds every panel about the
case, in the order the work runs: Data, Documents, Parties, Tasks,
Communication. Files, Notes, Mail and Related cases follow them until each
folds into its neighbour. Sub-cases, Locations, Appointments and Decisions
close the strip. Only the open tab loads, so opening a case does not fire a
request for the five panels you did not ask for.

## The history

The case keeps one history, and it is in the sidebar under History. It is the
audit trail of the case object: every create, update and delete, newest
first, with who did it and when. Open a row to see which fields changed and
what they changed from. The Action and User filters narrow the list, so you
can ask what one colleague did without reading past everyone else.

Three things it does not do yet, and each is a platform change rather than a
setting. It opens on every entry rather than on writes, so reads are in the
list until you filter them out. There is no export, so a history you have to
hand to someone outside the system still has to be copied out by hand. And it
holds audit rows only: a document upload, a note and a sent mail do not
appear beside a status change, because a merged feed per object is the
OpenRegister activity leaf and that does not exist yet.

The case page used to carry a second history tab, Version history, which
showed the same audit rows as a field diff. It is gone from this page. Other
detail pages still have it.

## Next

Configure the statuses a case moves through in
[Configure case types](../user-guide/admin/01-configure-case-types.md).
