# Design: widgets-my-open-cases-on-the-start-page

Read at dossiq `development` `db27acb6e` on 2026-09-27. dossiq supports
Nextcloud 32 to 34 (`appinfo/info.xml`).

## What is there

- Seven widgets in `lib/Dashboard`, all `implements IWidget`, each with a
  `load()` that adds its own script bundle. Ids are frozen at the old prefix
  (`procest_my_tasks_widget` and so on), with the reason in
  `CasesOverviewWidget::getId()`.
- `PersonalQueueService::forPerson($userId)` (`lib/Service/Queue/PersonalQueueService.php:81`)
  answers the one personal queue from its sources (`AssignedCasesSource`,
  `EngineTaskSource`, `CoveredWorkSource` and eight more), each item carrying
  `title`, `subjectType`, `subjectId`, `dueAt`, `priority` and `route`
  (`QueueItem::jsonSerialize()`). The My work page reads the same service
  through `GET /api/personal-queue`.
- The My work page is `#MyWork`, route `/my-work`.

## D-1. One new widget over the one queue

`MyOpenWorkWidget` implements `IWidget`, `IAPIWidgetV2`, `IButtonWidget`,
`IIconWidget` and `IReloadableWidget`. `getItemsV2($userId, $since, $limit)`
calls `forPerson()` for that user and maps each item to a `WidgetItem`: title,
a subtitle of the due date and the source label, the link from `route` made
absolute, and an icon per `subjectType`. Items are ordered by `dueAt`, empty
dates last. `getButtons()` answers one button, "Open my work", to `/my-work`,
and the item list's `emptyContentMessage` reads "Nothing waiting for you."

The widget reads the queue as the user, through the same service the page
uses, so the widget and the page cannot disagree about what is open.

## D-2. My tasks gains the same interfaces

`MyTasksWidget` keeps its id and its `load()`, and gains `IAPIWidgetV2` over
the engine task source of the same queue. That is the widget a start page most
often holds today, and it is the one the launchpad note names.

## D-3. The limit and the rest

`getItemsV2` answers at most `$limit` items (the host's choice, 7 by default).
When the queue holds more, the last item is replaced by one that reads "{n}
more in My work" and links there. A start page tile should show the next thing
to do, not the whole list.

## Risks

- `forPerson()` reads eleven sources. The widget endpoint is called on every
  start page load, so the service's per-source failure handling matters: a
  source that throws is listed as unavailable and the others still answer
  (`PersonalQueueService.php:104-112`). The widget shows what it has.
- `IReloadableWidget` asks the host to poll. The reload interval is 300 seconds,
  not the default 60, to keep eleven source reads off every open start page.
