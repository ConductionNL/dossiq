# Tasks: widgets-my-open-cases-on-the-start-page

Tier: V1. Kind: code. Row: launchpad `d-work-queue`.

- [x] 1.1 `lib/Dashboard/MyOpenWorkWidget.php` (design D-1 and D-3): items from
  `PersonalQueueService::forPerson()`, ordered by due date, the "more in My
  work" item, the Open my work button, reload every 300 seconds; registered in
  `AppHostRegistrar`.
  - unit: `MyOpenWorkWidgetTest` maps a case item and a task item, orders them,
    and replaces the last item when the queue holds more than the limit
  - `composer check:strict` exit 0
  - built: `lib/Dashboard/MyOpenWorkWidget.php` over `lib/Dashboard/QueueWidgetItems.php`,
    tests `tests/Unit/Dashboard/MyOpenWorkWidgetTest.php` and
    `tests/Unit/Dashboard/QueueWidgetItemsTest.php`; strings en and nl. The widget
    id is `dossiq_my_open_work_widget` (a new widget takes the current app id).
- [x] 1.2 `MyTasksWidget` implements `IAPIWidgetV2` over the engine task source
  (design D-2); id and `load()` unchanged.
  - unit: items for a user with two open tasks; the id is still
    `procest_my_tasks_widget`
  - built: `lib/Dashboard/MyTasksWidget.php::getItemsV2()`, tested in
    `MyOpenWorkWidgetTest` (two tasks, and an engine that cannot be read says so).
- [ ] 1.3 (live pass, decision 139) Live check on a dev instance: `GET /ocs/v2.php/apps/dashboard/api/v2/widget-items?widgets[]=<id>`
  as a handler with open work answers the items, and the Nextcloud dashboard
  shows the widget with the button.
  - the curl output and a screenshot in the PR
- [x] 1.4 `tests/e2e/my-open-work-widget.spec.ts` (written; its run is the live pass, decision 139): the widget lists an assigned
  case and its link opens the case; citing the scenarios below.
