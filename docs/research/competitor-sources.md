# Competitor sources

One entry per system in the parity ledger, so a reader of [competitor parity, September 2026](competitor-parity-2026-09.md) can open the code, the documentation, the API reference and the issue tracker behind a cell, and the corpus directory that holds the column. Generated from the ledger's sources register (`data.family.sources` in `procest/_ledger/parity-ledger.html`, `ConductionNL/market-intelligence`, `development`, ledger v6). Every link was fetched on 2026-09-13; a link marked *to verify* did not answer a plain fetch that day, and the note says what it answered instead.

Corpus paths are relative to the `market-intelligence` repository. A column's ratings live in the system's `round<N>/` directory; the cross-system files live in `procest/_round<N>/compare/`.

## Evidence classes

| class | meaning |
|---|---|
| driven | The system was installed, seeded and driven, and every rating carries a file path, a screen or a computation the live engine returned. This is the only class that may produce a new matrix row. |
| documented | Closed source and not driven: rated from the API reference, the documentation, the public tracker, the release notes and the price pages, every cell quoting the page it rests on. Printed under its own label, `documented, not driven`, wherever its number appears, and never in a driven count or a driven ranking. An upper bound: every driven batch found something the vendor's page did not say. A driven column replaces it if a licence and a clock are ever spent. |
| trial | Self-hostable only under a paid or time-limited licence. Can reach the driven standard, at a cost and with a clock on it. |
| docs only | Cloud only, so it cannot be installed and driven. Ratings would come from a feature page, which is the exact thing round 2 was built to stop. Include for landscape, never as evidence. |

## Driven: installed, seeded and driven

### GLPI

| | |
|---|---|
| version read | 11.0.8 |
| family | ITSM help desk |
| licence | GPL-3.0 |
| open core | no, verified in round 3: the only commercial hook is a support key that changes the marketplace listing |
| code | [glpi-project/glpi](https://github.com/glpi-project/glpi) |
| documentation | [user documentation](https://glpi-user-documentation.readthedocs.io/); [developer documentation](https://glpi-developer-documentation.readthedocs.io/en/master/) |
| API | [legacy REST API, apirest.md](https://github.com/glpi-project/glpi/blob/main/apirest.md); [high-level API](https://glpi-developer-documentation.readthedocs.io/en/master/devapi/hlapi/index.html) |
| issue tracker | [GitHub issues](https://github.com/glpi-project/glpi/issues) |
| corpus | procest/glpi/round3/ and procest/_round3/compare/ |
| note | The user documentation root resolves to the French build; `/en/latest/` answered 404 on 2026-09-13. |

### Zammad

| | |
|---|---|
| version read | 7.1.3 |
| family | help desk |
| licence | AGPL-3.0 |
| open core | no, verified in round 3: zero hits for licence_key, licensed_feature or entitlement |
| code | [zammad/zammad](https://github.com/zammad/zammad) |
| documentation | [admin documentation](https://admin-docs.zammad.org); [user documentation](https://user-docs.zammad.org); [system documentation](https://docs.zammad.org) |
| API | [REST API](https://docs.zammad.org/en/latest/api/intro.html) |
| issue tracker | [GitHub issues](https://github.com/zammad/zammad/issues) |
| corpus | procest/zammad/round3/ and procest/_round3/compare/ |

### OpenProject

| | |
|---|---|
| version read | 16.6.10 |
| family | issue and project |
| licence | GPL-3.0 |
| open core | yes: 32 features gated at runtime, four degrade silently. Verified on the running instance, `GET /api/v3/configuration` returned an empty `availableFeatures` |
| code | [opf/openproject](https://github.com/opf/openproject) |
| documentation | [documentation](https://www.openproject.org/docs/) |
| API | [API v3 documentation](https://www.openproject.org/docs/api/) |
| issue tracker | [community work packages](https://community.openproject.org/projects/openproject/work_packages); [GitHub issues](https://github.com/opf/openproject/issues) |
| named on a comparison page | Easy8, Atlassian Data Center alternatives; dev.to, OpenProject vs Jira |
| corpus | procest/openproject/round4/ and procest/_round4/compare/ (PR 104) |
| note | The Atw measurement ran on its two calculators: `procest/openproject/round4/op-engine*.rb`. |

### Plane Community

| | |
|---|---|
| version read | v1.4.2 |
| family | issue |
| licence | AGPL-3.0 |
| open core | yes, by omission: the paid features are absent rather than gated, and the seams are visible |
| code | [makeplane/plane](https://github.com/makeplane/plane) |
| documentation | [documentation](https://docs.plane.so) |
| API | [developer documentation and API reference](https://developers.plane.so) |
| issue tracker | [GitHub issues](https://github.com/makeplane/plane/issues) |
| named on a comparison page | Easy8, Atlassian Data Center alternatives; dev.to, OpenProject vs Jira |
| corpus | procest/plane/round4/ and procest/_round4/compare/ (PR 104) |

### Redmine

| | |
|---|---|
| version read | 7.0.1 |
| family | issue |
| licence | GPL-2.0 |
| open core | no: zero entitlement hits in 351 Ruby files. Commercial pressure sits outside the tree, in plugins and in Easy Redmine |
| code | [official Subversion](https://svn.redmine.org/redmine/); [GitHub mirror redmine/redmine](https://github.com/redmine/redmine) |
| documentation | [guide](https://www.redmine.org/projects/redmine/wiki/Guide) |
| API | [REST API](https://www.redmine.org/projects/redmine/wiki/Rest_api) |
| issue tracker | [redmine.org issues](https://www.redmine.org/projects/redmine/issues) |
| named on a comparison page | Easy8, Atlassian Data Center alternatives |
| corpus | procest/redmine/round4/ and procest/_round4/compare/ (PR 105) |

### Forgejo

| | |
|---|---|
| version read | 16.0.4 |
| family | forge issues |
| licence | GPL-3.0 on fork-authored files, MIT on the 2,458 inherited ones |
| open core | no: zero entitlement hits in 3,314 Go files |
| code | [codeberg.org/forgejo/forgejo](https://codeberg.org/forgejo/forgejo) |
| documentation | [documentation](https://forgejo.org/docs/latest/) |
| API | [API usage](https://forgejo.org/docs/latest/user/api-usage/); [Swagger on Codeberg](https://codeberg.org/api/swagger) |
| issue tracker | [Codeberg issues](https://codeberg.org/forgejo/forgejo/issues) |
| corpus | procest/forgejo/round4/ and procest/_round4/compare/ (PR 105) |

### osTicket

| | |
|---|---|
| version read | 1.18.4 |
| family | ticket |
| licence | GPL-2.0 |
| open core | no: six entitlement greps, zero hits. Eight free capabilities live in a separate plugins repository last pushed nineteen months before the core |
| code | [osTicket/osTicket](https://github.com/osTicket/osTicket) |
| documentation | [documentation](https://docs.osticket.com/) |
| API | [API documentation in the tree](https://github.com/osTicket/osTicket/tree/develop/setup/doc/api) |
| issue tracker | [GitHub issues](https://github.com/osTicket/osTicket/issues); [forum](https://forum.osticket.com/) |
| corpus | procest/osticket/round4/ and procest/_round4/compare/ |
| note | The documentation build is titled 1.17.7, one release behind the version driven. |

### FreeScout

| | |
|---|---|
| version read | 1.8.240 |
| family | ticket |
| licence | AGPL-3.0 |
| open core | yes: 72 of 75 modules priced, 539.73 dollars for the set, 33 matrix rows gated, and the paywall runs through the free half |
| code | [freescout-help-desk/freescout](https://github.com/freescout-help-desk/freescout) |
| documentation | [GitHub wiki](https://github.com/freescout-help-desk/freescout/wiki); [module store](https://freescout.net/modules/) |
| API | [API documentation](https://api-docs.freescout.net/) |
| issue tracker | [GitHub issues](https://github.com/freescout-help-desk/freescout/issues) |
| corpus | procest/freescout/round4/ and procest/_round4/compare/ |
| note | `freescout.net/docs/` answered 404 on 2026-09-13; the wiki is the documentation. |

### Znuny

| | |
|---|---|
| version read | 7.3.6 |
| family | ticket |
| licence | AGPL-3.0 and GPL |
| open core | no: 37 vendor packages, none priced. Six entitlement greps over Kernel/ and bin/ return zero hits |
| code | [znuny/Znuny](https://github.com/znuny/Znuny) |
| documentation | [documentation index](https://doc.znuny.org/); [LTS administrator manual](https://doc.znuny.org/znuny_lts/); [developer manual](https://doc.znuny.org/developer/) |
| API | [Perl API reference](https://doc.znuny.org/znuny-dev-api/); [web services, the generic interface](https://doc.znuny.org/znuny_lts/admin/webservices/index.html) |
| issue tracker | [GitHub issues](https://github.com/znuny/Znuny/issues) |
| corpus | procest/znuny/round4/ and procest/_round4/compare/ (PR 107) |
| note | `znuny.org` timed out twice on 2026-09-13; the documentation host answers. |

### GitLab CE

| | |
|---|---|
| version read | 19.3.2 |
| family | forge issues |
| licence | MIT |
| open core | yes, by subtraction: the paid code is removed at build time, leaving 1,616 no-op seams in 1,599 files. 22 rows need EE, each settled by a 404 or 400 from the running instance |
| code | [gitlab-org/gitlab, one tree builds both editions](https://gitlab.com/gitlab-org/gitlab); [gitlab-org/gitlab-foss, the CE-only mirror](https://gitlab.com/gitlab-org/gitlab-foss) |
| documentation | [documentation](https://docs.gitlab.com/) |
| API | [REST API](https://docs.gitlab.com/api/rest/) |
| issue tracker | [work items, formerly issues](https://gitlab.com/gitlab-org/gitlab/-/work_items) |
| named on a comparison page | Linear, the modern alternative to Jira; Easy8, Atlassian Data Center alternatives; dev.to, OpenProject vs Jira |
| corpus | procest/gitlab/round4/ and procest/_round4/compare/ (PR 107) |
| note | `/-/issues` redirects to `/-/work_items` and answers 404 to a plain fetch, 200 in a browser. |

### OTOBO

| | |
|---|---|
| version read | 11.0.17, the vendor image `rotheross/otobo` at `rel-11_0_17` |
| family | ticket |
| licence | GPL-3.0 |
| open core | no: six entitlement greps over `Kernel/` and `bin/` return nothing that matters, and the four package repositories list 45 free packages, 44 GPL-3.0 and 1 AGPL-3.0, none priced. Four core capabilities ship switched off, and with `Ticket::Service` at 0 an SLA is accepted and ignored |
| code | [RotherOSS/otobo](https://github.com/RotherOSS/otobo); [RotherOSS/otobo-docker](https://github.com/RotherOSS/otobo-docker) |
| documentation | [documentation index](https://doc.otobo.org/); [administration manual 11.0](https://doc.otobo.org/manual/admin/11.0/en/content/index.html); [installation manual 11.0](https://doc.otobo.org/manual/installation/11.0/en/content/index.html) |
| API | [developer manual, the generic interface](https://doc.otobo.org/) (to verify) |
| issue tracker | [GitHub issues](https://github.com/RotherOSS/otobo/issues) |
| corpus | procest/otobo/round4/ and procest/_round4/compare/ (PR 108) |
| note | The documentation index renders by script, so a plain fetch finds no manual links, and every developer-manual path tried under `doc.otobo.org/manual/developer/11.0/` answered 404 on 2026-09-13. The API is the generic interface, driven in the corpus at `nph-genericinterface.pl`. |

### iTop Community

| | |
|---|---|
| version read | 3.2.3-2 build 20678, built from the release zip `iTop-3.2.3-2-20678.zip` |
| family | ITSM and CMDB |
| licence | AGPL-3.0 |
| open core | yes, as a catalogue: the zip carries no key and no gate, 1,955 PHP files carry the AGPL header, and five entitlement greps return 0 hits. The store lists 103 extensions, 70 AGPL and 33 under the Combodo Software License, none in the tree, six matrix rows behind them |
| code | [Combodo/iTop](https://github.com/Combodo/iTop) |
| documentation | [iTop Hub wiki](https://www.itophub.io/wiki/page); [administrator guide](https://www.itophub.io/wiki/page?id=latest:admin:start); [extension store, all extensions](https://store.itophub.io/en_US/taxons/all-extensions) |
| API | [REST/JSON services](https://www.itophub.io/wiki/page?id=latest:advancedtopics:rest_json) |
| issue tracker | [GitHub issues](https://github.com/Combodo/iTop/issues); [SourceForge tickets](https://sourceforge.net/p/itop/tickets/) (to verify) |
| corpus | procest/itop/round4/ and procest/_round4/compare/ (PR 108) |
| note | Combodo publishes no image of its own, so the column was built from the release zip. `sourceforge.net/p/itop/tickets/` answered 403 to a plain fetch on 2026-09-13. |

### Odoo Community

| | |
|---|---|
| version read | 19.0, the vendor's own image, the database created from the command line with the Project app and its Community neighbours |
| family | project management |
| licence | LGPL-3.0 |
| open core | yes, by absence with a label. The Enterprise addons live in a private repository under OEEL-1 and are not in the tree; no key, no gate and no degraded path in Community, `grep -rliE 'license.?key|licen[cs]e.?check|entitlement|enterprise_code'` finds the `publisher_warranty` phone-home and nothing that compares a key. `odoo/addons/base/data/ir_module_module.xml` ships 21 `to_buy` records under OEEL-1 for modules the tree does not contain, and 45 `widget="upgrade_boolean"` toggles render an Enterprise badge. Twelve matrix rows need an Enterprise addon. The release-by-release claim holds for 2015 to 2019 and has no example since |
| code | [odoo/odoo on GitHub, branch 19.0. GitHub reports the licence as NOASSERTION; the `LICENSE` file says LGPLv3](https://github.com/odoo/odoo/tree/19.0); [`addons/project` on 19.0](https://github.com/odoo/odoo/tree/19.0/addons/project); [`odoo/addons/base/data/ir_module_module.xml`, the 21 `to_buy` records](https://github.com/odoo/odoo/blob/19.0/odoo/addons/base/data/ir_module_module.xml); [`addons/resource/models/resource_calendar.py`, the working calendar](https://github.com/odoo/odoo/blob/19.0/addons/resource/models/resource_calendar.py); [`addons/mail/models/mail_thread.py`, the reply threading](https://github.com/odoo/odoo/blob/19.0/addons/mail/models/mail_thread.py) |
| documentation | [Project app, 19.0](https://www.odoo.com/documentation/19.0/applications/services/project.html); [editions comparison, table renders by script](https://www.odoo.com/page/editions) (to verify) |
| API | [External API, XML-RPC, 19.0](https://www.odoo.com/documentation/19.0/developer/reference/external_api.html) |
| issue tracker | [GitHub issues, about 3,800 open](https://github.com/odoo/odoo/issues) |
| corpus | procest/odoo/round4/ and procest/_round4/compare/ (PR 110) |
| note | Same family as OpenProject, Plane and Redmine. Not a ticket system: Community ships no `addons/helpdesk`, and Helpdesk is a `to_buy` record. Driven in batch 6 beside Nextcloud Deck, on port 8095, torn down before Deck came up. |

### Nextcloud Deck

| | |
|---|---|
| version read | 1.18.4, chosen by `occ app:install deck` on a throwaway Nextcloud 34.0.4 of its own; source read at the tag `v1.18.4` |
| family | kanban |
| licence | AGPL-3.0 |
| open core | no: `appinfo/info.xml` says `agpl`, the 188 PHP files carry `AGPL-3.0-or-later` headers, and `grep -rniE 'licen[cs]e|subscription|premium|enterprise' lib` returns those headers and nothing else. No key, no tier, no paid module. Not a shape; what decides its score is which host surfaces it plugs into |
| code | [nextcloud/deck on GitHub](https://github.com/nextcloud/deck); [the tag `v1.18.4`](https://github.com/nextcloud/deck/tree/v1.18.4); [`lib/Service/FilesAppService.php`, the attachment as a Files node](https://github.com/nextcloud/deck/blob/v1.18.4/lib/Service/FilesAppService.php) |
| documentation | [app store listing](https://apps.nextcloud.com/apps/deck) |
| API | [REST API, `docs/API.md` at `v1.18.4`](https://github.com/nextcloud/deck/blob/v1.18.4/docs/API.md) |
| issue tracker | [GitHub issues](https://github.com/nextcloud/deck/issues) |
| corpus | procest/nextcloud-deck/round4/ and procest/_round4/compare/ (PR 110) |
| note | The one candidate on the same host as dossiq, driven on port 8096 and never on the shared instance. The unified search provider ids are `search-deck-card-board` and `search-deck-comment`, not `deck`; an upload needs `data=<filename>` beside `type=file` or answers 400 with an empty body. |

### OpenCase

| | |
|---|---|
| version read | 1.1.0, commit 1143dbea (2026-09-05) |
| family | Danish municipal case system on Nextcloud |
| licence | AGPL-3.0-or-later |
| open core | yes: the enterprise edition (AI, digital post, CPR and CVR) is a separate non-public package, gated on `enterprise_version` in `lib/Service/Configuration.php:140` per the round 2 census |
| code | [lamotech/opencase](https://github.com/lamotech/opencase) |
| documentation | [docs.opencase.dk](https://docs.opencase.dk/); [Nextcloud app store](https://apps.nextcloud.com/apps/opencase) |
| API | no separate API reference found; the census read `appinfo/routes.php` (to verify) |
| issue tracker | [GitHub issues](https://github.com/lamotech/opencase/issues) |
| corpus | procest/opencase/round2/ and procest/_round2/compare/ |
| note | Repository last pushed 2026-09-12, 11 open issues. |

### GZAC / Valtimo

| | |
|---|---|
| version read | 13.4.1, demo Evenementenvergunning; monorepo `next-minor` at 51eddc25 |
| family | Dutch case and BPM platform |
| licence | EUPL-1.2 |
| open core | partly: release builds pull two closed-published npm packages, `@valtimo-plugins/freemarker` and `@valtimo-plugins/smtpmail`, per the round 2 census |
| code | [valtimo-platform/valtimo, the monorepo](https://github.com/valtimo-platform/valtimo); [valtimo-docker-compose](https://github.com/valtimo-platform/valtimo-docker-compose); [gzac-docker-compose](https://github.com/generiekzaakafhandelcomponent/gzac-docker-compose) |
| documentation | [docs.valtimo.nl](https://docs.valtimo.nl/); [docs.gzac.nl](https://docs.gzac.nl/) |
| API | no API reference page found under docs.valtimo.nl; a running backend serves its own OpenAPI (to verify) |
| issue tracker | [GitHub issues](https://github.com/valtimo-platform/valtimo/issues) |
| corpus | procest/valtimo/round2/ and procest/_round2/compare/ |
| note | `valtimo-backend-libraries` and `valtimo-frontend-libraries` are archived, last pushed 2025-10-08 and 2025-09-26. The monorepo was pushed 2026-09-11. |

### xxllnc Zaken (Zaaksysteem)

| | |
|---|---|
| version read | `master` at b7354824, driven locally at the `dev.zaaksysteem.nl` hostname the compose file insists on |
| family | Dutch case system |
| licence | EUPL-1.2 |
| open core | not established |
| code | [gitlab.com/xxllnc/zaakgericht/zaken/start](https://gitlab.com/xxllnc/zaakgericht/zaken/start) |
| documentation | [open source wiki](https://wiki.zaaksysteem.nl/wiki/Open_Source); [community](https://community.zaaksysteem.nl/); [frontend storybook](https://zaaksysteem.gitlab.io/zaaksysteem-frontend-mono); [help.zaaksysteem.nl, bot wall on a plain fetch](https://help.zaaksysteem.nl/hc/nl) (to verify); [docs.zaaksysteem.nl, 502 three times on 2026-09-13](https://docs.zaaksysteem.nl/) (to verify) |
| API | the census read `backend/perl-api/share/apidocs` in the tree; no public API site confirmed (to verify) |
| issue tracker | `/-/issues` on the start project answered 404, so issues look disabled (to verify) |
| corpus | procest/xxllnc-zaken/round2/ and procest/_round2/compare/ |
| note | `github.com/zaaksysteem/zaaksysteem` answers 404 now; `mintlab/Zaaksysteem` was last pushed in 2020. The GitLab project was active on 2026-09-11. |

### Dimpact ZAC

| | |
|---|---|
| version read | commit 06b64668, tag zaakafhandelcomponent-1.0.316, image `ghcr.io/infonl/zaakafhandelcomponent:latest` |
| family | Dutch case component on Open Zaak |
| licence | EUPL-1.2-or-later |
| open core | not established; nothing seen |
| code | [infonl/dimpact-zaakafhandelcomponent](https://github.com/infonl/dimpact-zaakafhandelcomponent) |
| documentation | [docs in the tree, manuals included](https://github.com/infonl/dimpact-zaakafhandelcomponent/tree/main/docs); [development README](https://github.com/infonl/dimpact-zaakafhandelcomponent/blob/main/docs/development/README.md) |
| API | no API reference confirmed outside the tree (to verify) |
| issue tracker | [GitHub issues](https://github.com/infonl/dimpact-zaakafhandelcomponent/issues) |
| corpus | procest/zac/round3/ on branch `feat/round3-dimpact-zac`, PR 100, open and unmerged on 2026-09-13. Round 1 material at procest/dimpact-zac/ on development |
| note | The column is the `zac` key on every ledger row. It is `unknown` on the 19 rows round 3 added. |

### Kanboard

| | |
|---|---|
| version read | 1.2.54, the project's own image `kanboard/kanboard`, SQLite; source read at the tag `v1.2.54` the image reports over `getVersion` |
| family | task |
| licence | MIT |
| open core | no: `LICENSE` is MIT, and `licen[cs]e`, `subscription`, `premium`, `enterprise`, `feature.?flag` and `paid|pricing|purchase` over `app/` outside `Locale/` return 4, 0, 0, 0, 0 and 0, the four being the About page printing the licence. `kanboard.org/plugins.json` listed 163 plugins on 2026-09-13 (152 MIT, 7 Unlicense, 2 GPL-3.0, 2 Creative Commons, 145 installable from inside the product) with no price field and 0 hits for `price|paid|commercial|premium|purchase|buy`. Redmine's shape: a complete core with third parties beside it |
| code | [kanboard/kanboard on GitHub](https://github.com/kanboard/kanboard); [the tag `v1.2.54`](https://github.com/kanboard/kanboard/tree/v1.2.54); [`app/Action/`, the fifty event-to-action classes](https://github.com/kanboard/kanboard/tree/v1.2.54/app/Action); [`app/Model/TaskRecurrenceModel.php`, the calendar-day arithmetic](https://github.com/kanboard/kanboard/blob/v1.2.54/app/Model/TaskRecurrenceModel.php); [`app/Model/LinkModel.php`, link labels with an opposite](https://github.com/kanboard/kanboard/blob/v1.2.54/app/Model/LinkModel.php) |
| documentation | [documentation](https://docs.kanboard.org/) |
| API | [JSON-RPC API reference](https://docs.kanboard.org/v1/api/) |
| issue tracker | [GitHub issues](https://github.com/kanboard/kanboard/issues) |
| corpus | procest/kanboard/round4/ and procest/_round4/compare/ (PR 115) |
| note | Driven in batch 7 beside Vikunja on port 8091, both up together because each is one container. The Docker credential helper on the host (`desktop.exe`) refuses a pull from a shell; an empty `DOCKER_CONFIG` works. `moveTaskPosition` needs `swimlane_id` or answers invalid params. An automatic action on `task.create` fires on the task a recurrence creates. |

### Vikunja

| | |
|---|---|
| version read | 2.6.0, the project's own image `vikunja/vikunja`, API and frontend in one Go binary, SQLite; source read at the tag `v2.6.0` the image reports on `/api/v1/info` |
| family | task |
| licence | AGPL-3.0 |
| open core | yes, the tenth shape, a gate that hides. `pkg/license/license.go:70-74` names three features, admin_panel, time_tracking and audit_logs; `RequireFeature` (`pkg/routes/feature_gate.go:24`) serves 404 for them "so gated routes are indistinguishable from unregistered ones"; `licenseFeatureForRoute` hides them from the token scope list, the audit listener drops events, the instance-admin flag is inert and the CLI refuses to set it. With a key, `pkg/license/check.go:57-66` posts the key, the version, the database type, the counts of active, disabled and pending users, the host OS and a container flag to `console.vikunja.io` daily; without one nothing calls out. The free install creates `license_status` and `time_entries`. Verified live: `enabled_pro_features: []`, four gated routes 404, `/admin` "Not found" |
| code | [go-vikunja/vikunja on GitHub](https://github.com/go-vikunja/vikunja); [the tag `v2.6.0`](https://github.com/go-vikunja/vikunja/tree/v2.6.0); [`pkg/license/license.go`, the three features and the comment to whoever reads it](https://github.com/go-vikunja/vikunja/blob/v2.6.0/pkg/license/license.go); [`pkg/routes/feature_gate.go`, the 404](https://github.com/go-vikunja/vikunja/blob/v2.6.0/pkg/routes/feature_gate.go); [`pkg/models/task_relation.go`, eleven kinds and the inverse](https://github.com/go-vikunja/vikunja/blob/v2.6.0/pkg/models/task_relation.go); [`pkg/models/project_access.go`, the recursive access query](https://github.com/go-vikunja/vikunja/blob/v2.6.0/pkg/models/project_access.go) |
| documentation | [documentation](https://vikunja.io/docs/) |
| API | [Swagger 2 for v1, on any instance at `/api/v1/docs.json`; OpenAPI 3 for v2 at `/api/v2/openapi.json`](https://try.vikunja.io/api/v1/docs) |
| issue tracker | [GitHub issues](https://github.com/go-vikunja/vikunja/issues) |
| named on a comparison page | dev.to, OpenProject vs Jira |
| corpus | procest/vikunja/round4/ and procest/_round4/compare/ (PR 115) |
| note | `kolaente.dev/vikunja/vikunja` asks for a sign-in; GitHub is the public copy. Driven in batch 7 beside Kanboard on port 8092. Every registered user gets an Inbox that takes the next project id, so probe the project you made, not project 2. A task `POST` and the user settings `POST` are full replaces. The image runs as uid 1000 and a fresh volume is root's; the log names the fix. |

## Documented, not driven

### Jira Service Management

| | |
|---|---|
| version read | Cloud, read 2026-09-13; Data Center 11.3 where Cloud and DC differ. Not driven: no account, no instance, no screenshot |
| family | ITSM |
| licence | proprietary |
| open core | yes, by plan: the ninth shape. The paid half is a column in a plan table; there is no code to read, every capability is in every tenant's build, and the plan decides whether it renders. The column rates Standard, and eleven rows move by plan: custom objects, the customer record, skill routing, playbooks, the audit log, staff SSO, enforced two-factor, analytics, retention, the holiday import and S3. The boundary moves by date without a version: incident, change and problem management moved to Premium on 2024-10-16. Counted by script, 16 cells name Premium, 8 Enterprise, 5 Atlassian Guard and 2 Data Center. Read in `procest/jira-service-management/round4/open-core.md` |
| code | closed source |
| documentation | [Set up SLA calendars](https://support.atlassian.com/jira-service-management-cloud/docs/set-up-sla-calendars/); [Categorize customer requests into request types](https://support.atlassian.com/jira-service-management-cloud/docs/what-are-request-types/); [Create and edit SLA calendars, Data Center, last modified 2024-03-19](https://confluence.atlassian.com/servicemanagementserver/create-and-edit-sla-calendars-976770869.html); 108 URLs in this group, every one listed in `procest/jira-service-management/round4/sources.md` |
| API | [Request, the `servicedeskapi` group whose 72 operations hold no calendar resource](https://developer.atlassian.com/cloud/jira/service-desk/rest/api-group-request/); [Customer, with the portal-only account](https://developer.atlassian.com/cloud/jira/service-desk/rest/api-group-customer/); [Jira Service Management Data Center REST API 11.3](https://developer.atlassian.com/server/jira-servicedesk/rest/v1103/intro/); 8 URLs in this group, every one listed in `procest/jira-service-management/round4/sources.md` |
| issue tracker | [JSDCLOUD-4685, merge, 1,237 votes, Future Consideration](https://jira.atlassian.com/browse/JSDCLOUD-4685); [JRACLOUD-69298, field-level security, 585 votes](https://jira.atlassian.com/browse/JRACLOUD-69298); [JSDCLOUD-194, SLA reset, fixed 2026-04-02 after 640 votes](https://jira.atlassian.com/browse/JSDCLOUD-194); 10 URLs in this group, every one listed in `procest/jira-service-management/round4/sources.md` |
| named on a comparison page | ALVAO, Jira Service Management alternatives |
| corpus | procest/jira-service-management/round4/ (M1-column.md, open-core.md, sources.md with 136 URLs in seven groups) and procest/_round4/compare/ (PR 113) |
| note | Documented in batch 8, graded `documented, not driven`; a driven column replaces this one if a licence and a clock are ever spent. The Cloud pricing page renamed the product Service Collection in 2026; the documentation, the API and Data Center still say Jira Service Management. |

### YouTrack

| | |
|---|---|
| version read | Server 2026.2, help pages dated July 2026 in their footers, read 2026-09-13; Cloud where the price pages differ. Not driven: no account, no instance, no screenshot |
| family | issue |
| licence | proprietary |
| open core | no: "The full feature set, support, and AI Assistance continue to be included at no additional cost". Ten users and three agents free with everything on, reporters unlimited and free; the one boundary is historical, the user-pack plans that cannot create helpdesk projects and closed to renewal on 2025-10-01. Shape one, closed: what a buyer does not get is the source, which the shapes do not measure. Read in `procest/youtrack/round4/open-core.md` |
| code | closed source |
| documentation | [SLA policies, the whole calendar in three settings](https://www.jetbrains.com/help/youtrack/server/service-level-agreements.html); [Helpdesk projects](https://www.jetbrains.com/help/youtrack/server/helpdesk-projects.html); [Show version history, the comment rollback](https://www.jetbrains.com/help/youtrack/server/show-version-history.html); 125 URLs in this group, every one listed in `procest/youtrack/round4/sources.md` |
| API | [Issues, `POST /api/issues` with its documented refusals](https://www.jetbrains.com/help/youtrack/devportal/resource-api-issues.html); [Commands, the same grammar as the dialog and the commit message](https://www.jetbrains.com/help/youtrack/devportal/resource-api-commands.html); [REST API URL and endpoints](https://www.jetbrains.com/help/youtrack/devportal/api-url-and-endpoints.html); 32 URLs in this group, every one listed in `procest/youtrack/round4/sources.md` |
| issue tracker | [JT-95867, SLA calendars, Collecting feedback, 3 votes](https://youtrack.jetbrains.com/issue/JT-95867); [JT-62660, search inside attachments, Collecting feedback, 14 votes](https://youtrack.jetbrains.com/issue/JT-62660); [JT-67234](https://youtrack.jetbrains.com/issue/JT-67234); 4 URLs in this group, every one listed in `procest/youtrack/round4/sources.md` |
| named on a comparison page | Easy8, Atlassian Data Center alternatives |
| corpus | procest/youtrack/round4/ (M1-column.md, open-core.md, sources.md with 171 URLs in seven groups) and procest/_round4/compare/ (PR 113) |
| note | Documented in batch 8, graded `documented, not driven`; a driven column replaces this one if a licence and a clock are ever spent. The first closed product to score `yes` on pending 11.27, and the first system in the corpus with no holiday calendar at all. |

## Trial: self-hostable under a paid or time-limited licence

### Jira Software Data Center

| | |
|---|---|
| version read | not driven |
| family | issue |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [Data Center 11.3 documentation](https://confluence.atlassian.com/jirasoftwareserver); [product page](https://www.atlassian.com/enterprise/data-center/jira) |
| API | [Data Center REST API](https://developer.atlassian.com/server/jira/platform/rest/) |
| issue tracker | [JRASERVER](https://jira.atlassian.com/projects/JRASERVER/issues) |
| named on a comparison page | Linear, the modern alternative to Jira; Easy8, Atlassian Data Center alternatives; ALVAO, Jira Service Management alternatives; dev.to, OpenProject vs Jira |
| corpus | none |
| note | The `/software/jira/data-center` path answers 404; the enterprise path is the live one. To be rated from documents the way batch 8 rated Jira Service Management, under the same grade. |

### Easy8, formerly Easy Redmine

| | |
|---|---|
| version read | not driven |
| family | issue and project |
| licence | proprietary on a GPL Redmine core, to verify |
| open core | not applicable |
| code | closed source |
| documentation | [tutorials; `easyredmine.com/documentation` redirects here](https://www.easy8.com/resources/tutorials) |
| API | no API reference found (to verify) |
| issue tracker | no public tracker found |
| named on a comparison page | Easy8, Atlassian Data Center alternatives |
| corpus | none |
| note | `easyredmine.com` redirects to `easy8.com`. To be rated from documents the way batch 8 did, under the same grade. |

### ManageEngine ServiceDesk Plus

| | |
|---|---|
| version read | not driven |
| family | ITSM |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [admin guide](https://help.servicedeskplus.com/); [product page](https://www.manageengine.com/products/service-desk/) |
| API | [v3 API; the page title says Cloud, check which edition it documents](https://www.manageengine.com/products/service-desk/sdpop-v3-api/index.html) (to verify) |
| issue tracker | no public tracker |
| named on a comparison page | ALVAO, Jira Service Management alternatives |
| corpus | none |
| note | Moved from docs only to trial: an on-premises edition can be installed under licence. |

## Not yet driven

### Request Tracker

| | |
|---|---|
| version read | not yet driven |
| family | ticket |
| licence | GPL-2.0 per the GitHub licence field |
| open core | to verify |
| code | [bestpractical/rt](https://github.com/bestpractical/rt) |
| documentation | [documentation, resolves to 6.0.3](https://docs.bestpractical.com/rt/latest/) |
| API | [REST2](https://docs.bestpractical.com/rt/latest/RT/REST2.html) |
| issue tracker | [GitHub issues](https://github.com/bestpractical/rt/issues) |
| corpus | none yet |
| note | `issues.bestpractical.com` redirects to a login page. Batch 9 is driving it now, beside Frappe Helpdesk. |

### Tuleap

| | |
|---|---|
| version read | not yet driven |
| family | ALM |
| licence | GPL-2.0, to verify |
| open core | Enterprise edition exists, to verify against the tree |
| code | [source: `Enalean/tuleap` on GitHub answers 404, and `tuleap.net/plugins/git/tuleap/tuleap/stable` answers 403 to an anonymous browser](https://tuleap.net/plugins/git/tuleap/tuleap/stable) (to verify) |
| documentation | [docs.tuleap.org](https://docs.tuleap.org); [documentation source](https://github.com/Enalean/tuleap-documentation-en) |
| API | no API reference found (to verify) |
| issue tracker | [Requests tracker on tuleap.net](https://tuleap.net/plugins/tracker/?tracker=140) |
| named on a comparison page | Easy8, Atlassian Data Center alternatives |
| corpus | none yet |
| note | Getting a readable source tree is the first task; an account on tuleap.net may be enough. |

### Gitea

| | |
|---|---|
| version read | not yet driven |
| family | forge issues |
| licence | MIT per the GitHub licence field |
| open core | a commercial edition is sold, to verify |
| code | [go-gitea/gitea](https://github.com/go-gitea/gitea) |
| documentation | [documentation](https://docs.gitea.com/) |
| API | [API reference](https://docs.gitea.com/api/) |
| issue tracker | [GitHub issues](https://github.com/go-gitea/gitea/issues) |
| corpus | none yet |
| note | Forgejo forked from it; most of the column is a diff against Forgejo. |

### Taiga

| | |
|---|---|
| version read | not yet driven |
| family | agile |
| licence | MPL-2.0 per the GitHub licence field; the AGPL-3.0 in the candidate table above was a vendor-page claim |
| open core | to verify |
| code | [taigaio/taiga-back](https://github.com/taigaio/taiga-back) |
| documentation | [documentation](https://docs.taiga.io/) |
| API | [REST API](https://docs.taiga.io/api.html) |
| issue tracker | [GitHub issues](https://github.com/taigaio/taiga-back/issues); [tree.taiga.io](https://tree.taiga.io/project/taiga/issues) |
| named on a comparison page | dev.to, OpenProject vs Jira |
| corpus | none yet |
| note | `kaleidos-ventures/taiga-back` redirects to `taigaio/taiga-back`. The frontend repository was not checked. |

### Huly

| | |
|---|---|
| version read | not yet driven |
| family | issue and workspace |
| licence | EPL-2.0 per the GitHub licence field |
| open core | to verify |
| code | [hcengineering/platform](https://github.com/hcengineering/platform) |
| documentation | [documentation](https://docs.huly.io/) |
| API | [`docs.huly.io/api/` answers 404; an examples repository exists](https://github.com/hcengineering/huly-examples) (to verify) |
| issue tracker | [GitHub issues](https://github.com/hcengineering/platform/issues) |
| corpus | none yet |
| note | Last pushed 2026-08-27. |

## Docs only: cloud only, landscape not evidence

### Linear

| | |
|---|---|
| version read | cannot be driven |
| family | issue |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [documentation](https://linear.app/docs) |
| API | [developers](https://linear.app/developers); [API and webhooks](https://linear.app/docs/api-and-webhooks) |
| issue tracker | no public tracker |
| named on a comparison page | Linear, the modern alternative to Jira; Easy8, Atlassian Data Center alternatives; dev.to, OpenProject vs Jira |
| corpus | none |
| note | `developers.linear.app` redirects to `linear.app/developers`. |

### GitHub Issues

| | |
|---|---|
| version read | cannot be driven as a service |
| family | forge issues |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [issues documentation](https://docs.github.com/en/issues); [Enterprise Server 3.16 admin documentation](https://docs.github.com/en/enterprise-server@3.16/admin) |
| API | [REST API, issues](https://docs.github.com/en/rest/issues) |
| issue tracker | [public roadmap](https://github.com/github/roadmap/issues); [community discussions](https://github.com/orgs/community/discussions) |
| named on a comparison page | Linear, the modern alternative to Jira; dev.to, OpenProject vs Jira |
| corpus | none |
| note | Enterprise Server would move it to trial. |

### TOPdesk

| | |
|---|---|
| version read | cannot be driven |
| family | ITSM |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [SaaS documentation](https://docs.topdesk.com/); [product page](https://www.topdesk.com/) |
| API | [developers](https://developers.topdesk.com/) |
| issue tracker | no public tracker |
| named on a comparison page | ALVAO, Jira Service Management alternatives |
| corpus | none |

### Zendesk

| | |
|---|---|
| version read | cannot be driven |
| family | ticket |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [help centre](https://support.zendesk.com/hc/en-us) |
| API | [API reference](https://developer.zendesk.com/api-reference/) |
| issue tracker | no public tracker |
| named on a comparison page | Linear, the modern alternative to Jira |
| corpus | none |

### Freshservice

| | |
|---|---|
| version read | cannot be driven |
| family | ITSM |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [support portal](https://support.freshservice.com/) |
| API | [API](https://api.freshservice.com/) |
| issue tracker | no public tracker |
| named on a comparison page | ALVAO, Jira Service Management alternatives |
| corpus | none |

### HaloITSM

| | |
|---|---|
| version read | cannot be driven |
| family | ITSM |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [guides](https://haloitsm.com/guides/); [product page](https://haloitsm.com/) |
| API | [API documentation](https://haloitsm.com/apidoc/) |
| issue tracker | no public tracker |
| corpus | none |
| note | None of the four comparison pages named it in a plain fetch. |

### InvGate

| | |
|---|---|
| version read | cannot be driven |
| family | ITSM |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [product page](https://invgate.com/); [docs.invgate.com answered 503 on 2026-09-13](https://docs.invgate.com/) (to verify) |
| API | no API reference found (to verify) |
| issue tracker | no public tracker |
| named on a comparison page | ALVAO, Jira Service Management alternatives |
| corpus | none |

### TeamDynamix

| | |
|---|---|
| version read | cannot be driven |
| family | ITSM |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [product page](https://www.teamdynamix.com/); documentation site not found (to verify) |
| API | [Web API, Swagger](https://solutions.teamdynamix.com/TDWebApi/) |
| issue tracker | no public tracker |
| named on a comparison page | ALVAO, Jira Service Management alternatives |
| corpus | none |

### ALVAO

| | |
|---|---|
| version read | cannot be driven |
| family | ITSM |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [doc.alvao.com](https://doc.alvao.com/); [product page](https://www.alvao.com/) |
| API | no API reference found (to verify) |
| issue tracker | no public tracker |
| named on a comparison page | ALVAO, Jira Service Management alternatives |
| corpus | none |
| note | Publishes one of the four comparison pages. |

### ServiceNow

| | |
|---|---|
| version read | cannot be driven |
| family | ITSM |
| licence | proprietary |
| open core | not applicable |
| code | closed source |
| documentation | [documentation](https://www.servicenow.com/docs/) |
| API | [developer portal](https://developer.servicenow.com/dev.do) |
| issue tracker | no public tracker |
| corpus | none |
| note | `www.servicenow.com` refused a plain fetch; the documentation host answers. None of the four comparison pages named it in a plain fetch. |

## The comparison pages that seeded the candidate set

Four vendor or community comparison pages were read to find candidates the rounds had not named. The last column counts how often each product is named on the page.

| key | page | products named |
|---|---|---|
| L | [Linear, the modern alternative to Jira](https://linear.app/homepage/alternative) | Jira 14, GitHub 14, Zendesk 3, GitLab 3, Notion 2 |
| E | [Easy8, Atlassian Data Center alternatives](https://www.easy8.com/atlassian-data-center-alternatives-2) | Jira 113, Linear 21, GitLab 5, OpenProject 2, YouTrack 1, Tuleap 1, Redmine 1, Plane 1, Asana 1 |
| A | [ALVAO, Jira Service Management alternatives](https://www.alvao.com/en/compare-itsm-software/jira-service-management-alternatives) | Jira 14, Azure DevOps 2, TeamDynamix 1, TOPdesk 1, ManageEngine 1, InvGate 1, Freshservice 1 |
| D | [dev.to, OpenProject vs Jira](https://dev.to/selfhostingsh/openproject-vs-jira-2acp) | OpenProject 41, Jira 40, Linear 7, GitHub 3, Vikunja 1, Taiga 1, Plane 1, GitLab 1 |

## Where the corpus files are

| what | path |
|---|---|
| the ledger, source of record | `procest/_ledger/parity-ledger.html`, with `procest/_ledger/README.md` on how to work with it |
| the 206-row matrix, four Dutch columns | `procest/_round2/compare/M1-functionality.md` |
| round 3, GLPI and Zammad, and the 21 proposals | `procest/_round3/compare/` |
| round 4, batches 1 to 8, the tallies and the engines | `procest/_round4/compare/`, index in its `README.md` |
| the counting and ranking scripts | `procest/_round4/tools/corpus-tally.py`, `count-column.py`, `render-column.py`, `render-extra.py` |
| the gap register | `procest/_gaps/README.md`, `gap-register.md`, `gap-register.json`, `ownership-rules.md` |
