## ADDED Requirements

### Requirement: Lists that were pages open as views of Cases

The old addresses of Assigned to me, the team queue and the data subject
requests SHALL redirect to the Cases page with that view applied, and the
views SHALL filter as the pages did.

#### Scenario: A bookmark of Assigned to me
- GIVEN a bookmark of /my-work
- WHEN it is opened
- THEN the browser lands on `/cases?savedView=default:my-work`
- AND the Cases page lists the open cases assigned to the reader, as the page did

#### Scenario: A bookmark of the data subject requests
- GIVEN a bookmark of /privacy/requests
- WHEN it is opened
- THEN the browser lands on `/cases?savedView=default:data-subject-requests`

### Requirement: A view keeps what its page had

Each view that replaced a page SHALL carry that page's presentation as page
config overrides, so nothing a user had on the page is lost.

#### Scenario: The team queue view
- GIVEN the Cases page with the `team-queue` view applied
- THEN it shows the three counts, a chip per case type, the Team today and per-type cards and the Waiting column, and its menu entry carries the count badge

#### Scenario: A bookmark of the team queue
- GIVEN a bookmark of /queue
- WHEN it is opened
- THEN the Cases page opens with the `team-queue` view, every open case nobody picked up

#### Scenario: Complaints on an instance with the complaint case type
- GIVEN an instance with a case type identified `klacht-behandeling`
- WHEN the app loads its manifest
- THEN the Cases page has a Complaints view filtering on that case type

### Requirement: The menu entries of the retired pages keep their ids

The team queue and Assigned to me SHALL keep their menu entries `Queue` and
`MyWork`, each opening its view of the Cases page, so a user's pins of them
keep working and the default pins are unchanged.

#### Scenario: A user pinned Assigned to me
- GIVEN a user whose pins are `MyWork` and `Tasks`
- WHEN they open the menu
- THEN the pinned Assigned to me entry opens `/cases?savedView=default:my-work`

### Requirement: No case-type-specific pages or menu entries

Dossiq SHALL have no list page or menu entry per case type (Ruben, 20 October
2026). A list of one case type SHALL be a default view of the Cases page
(`woo-requests`, `complaints`, `data-subject-requests`, `subsidies`), and a
user who wants another makes a view. Pages over a case type's own records
SHALL be reached from settings or administration, never from the main menu.

#### Scenario: A bookmark of the subsidies list
- GIVEN a bookmark of /subsidies
- WHEN it is opened
- THEN the Cases page opens with the `subsidies` view, the cases of the subsidie case type

#### Scenario: A data subject request is filed from its view
- GIVEN the Cases page with the `data-subject-requests` view applied
- WHEN the reader chooses New data subject request
- THEN the create form asks for the request kind (`dataSubjectRequestType`)
