# Design: woo-refusal-grounds-list

## D-1. The four lists, side by side (input for task 1, not a decision)

Read on 2026-10-05: opencatalogi and filinq on `development`, dossiq at 55bbc761.

filinq, schema `base` (19):

| slug | name |
| --- | --- |
| art-5-1-1-a | A, Eenheid van de Kroon |
| art-5-1-1-b | B, Veiligheid van de Staat |
| art-5-1-1-c | C, Bedrijfs- en fabricagegegevens |
| art-5-1-1-d | D, Bijzondere en strafrechtelijke persoonsgegevens |
| art-5-1-1-e | E, Identificatienummers |
| art-5-1-2-a | F, Betrekkingen met andere staten |
| art-5-1-2-b | G, Economische of financiële belangen van de Staat |
| art-5-1-2-c | H, Opsporing en vervolging van strafbare feiten |
| art-5-1-2-d | I, Inspectie, controle en toezicht |
| art-5-1-2-e | J, Persoonlijke levenssfeer |
| art-5-1-2-f | K, Concurrentiegevoelige bedrijfsgegevens |
| art-5-1-2-g | L, Bescherming van het milieu |
| art-5-1-2-h | M, Beveiliging van personen en bedrijven |
| art-5-1-2-i | N, Goed functioneren van de Staat |
| art-5-1-4 | O, Geadresseerde eerst kennisnemen |
| art-5-1-5 | P, Onevenredige benadeling |
| art-5-1-6 | Q, Milieu-informatie, ernstige schade |
| art-5-2-1 | R, Persoonlijke beleidsopvattingen |
| art-5-2-2 | S, Tot personen herleidbare beleidsopvattingen |

opencatalogi, `WooService::WEIGERINGSGRONDEN` (15): `5.1.1.a` to `5.1.1.d`, `5.1.2.a` to
`5.1.2.i`, `5.2.a`, `5.2.e`. Against filinq: it has no 5.1.1 e, 5.1 lid 4, 5 or 6. Its 5.1.2.f
reads "Bescherming van het milieu", where filinq's f is the business data ground and milieu is g.
Its 5.1.2.i is the addressee ground, which filinq places at 5.1 lid 4.

dossiq spec (12): 5.1.1a, 5.1.1b, 5.1.2a, 5.1.2b, 5.1.2c, 5.1.2d, 5.1.2e, 5.1.2f, 5.1.2g, 5.1.2i,
5.2.1, 5.2.2. Its 5.1.2f is the confidential business data ground, which filinq places at 5.1.1 c.

dossiq code (10): `5.1.1` to `5.1.5` and `5.2.1` to `5.2.5`. Several of these labels sit on the
wrong article. For example, `5.1.4` reads "Persoonlijke beleidsopvattingen" and `5.2.1` reads
"Economische of financiele belangen van de Staat".

These are observations. They are not the settlement. Task 1 asks a person with the law in hand,
the consolidated Woo text on wetten.overheid.nl at the date of settling, to decide the list.

## D-2. The settlement (APPROVED BY RUBEN 9 Oct 2026, subject to the Verbatim check section)

Copied on 2026-10-09 from `~/memcap-work/build-all/dossiq/woo-refusal-grounds-D2-draft.md`, approved by Ruben van der Linde on 9 Oct 2026 (decision 133). The seed in `lib/Settings/register.d/84-woo-refusal-grounds.json` and the fixture `tests/fixtures/woo-refusal-grounds-d2.json` are section 2 of this text.

- **Drafted:** 2026-10-09, by an agent for Ruben van der Linde. Approved by him the same day (see below).
- **Settled by:** Ruben van der Linde, 9 Oct 2026 (decision 133). Approved subject to the "Verbatim check" section at the end, which is done and lists one correction.
- **Source:** Wet open overheid, BWBR0045754, consolidated text on wetten.overheid.nl,
  version **geldend van 15-08-2026 t/m heden** (read 2026-10-09). The latest amendments in that
  version are Stb. 2026, 187 to 190. Earlier versions in force: 01-05-2022, 01-08-2022,
  18-02-2023, 01-04-2023, 19-06-2024, 01-08-2024, 01-10-2024, 01-01-2025, 01-07-2025. So the
  2022 to 2024 amendments are in, and so are the 2025 and 2026 ones.
- **Articles read:** 5.1 (lid 1 to 7), 5.2 (lid 1 to 4), 5.3, 5.4, 5.4a, 5.5, 5.6, 5.7.
- **Fleet lists read:** on `origin/development`, 2026-10-09: opencatalogi
  `lib/Service/WooService.php` `WEIGERINGSGRONDEN`, filinq `lib/Settings/filinq_register.json`
  (`art-5-*` objects), dossiq `openspec/specs/woo-case-type/spec.md` scenario "Mandatory
  weigeringsgrond", dossiq `lib/Service/WOODocumentAssessmentService.php`
  `VALID_WEIGERINGSGRONDEN` plus `lib/Settings/templates/woo-verzoek.json`. Also checked:
  decidiq, integriq, portaliq (see section 5).

### 1. The law text, per ground

Quoted from the 15-08-2026 version. Each ground below is one letter or one paragraph.

**Artikel 5.1 lid 1 (absolute grounds).** "Het openbaar maken van informatie ingevolge deze wet
blijft achterwege voor zover dit:"

- a. "de eenheid van de Kroon in gevaar zou kunnen brengen;"
- b. "de veiligheid van de Staat zou kunnen schaden;"
- c. "bedrijfs- en fabricagegegevens betreft die door natuurlijke personen of rechtspersonen
  vertrouwelijk aan de overheid zijn meegedeeld;"
- d. "persoonsgegevens betreft als bedoeld in paragraaf 3.1 onderscheidenlijk paragraaf 3.2 van
  de Uitvoeringswet Algemene verordening gegevensbescherming, tenzij de betrokkene uitdrukkelijk
  toestemming heeft gegeven voor de openbaarmaking van deze persoonsgegevens of deze
  persoonsgegevens kennelijk door de betrokkene openbaar zijn gemaakt;"
- e. "nummers betreft die dienen ter identificatie van personen die bij wet of algemene maatregel
  van bestuur zijn voorgeschreven als bedoeld in artikel 46 van de Uitvoeringswet Algemene
  verordening gegevensbescherming, tenzij de verstrekking kennelijk geen inbreuk op de
  levenssfeer maakt."

**Artikel 5.1 lid 2 (relative grounds).** "Het openbaar maken van informatie blijft eveneens
achterwege voor zover het belang daarvan niet opweegt tegen de volgende belangen:"

- a. "de betrekkingen van Nederland met andere landen en staten en met internationale
  organisaties;"
- b. "de economische of financiële belangen van de Staat, andere publiekrechtelijke lichamen of
  bestuursorganen, in geval van milieu-informatie slechts voor zover de informatie betrekking
  heeft op handelingen met een vertrouwelijk karakter;"
- c. "de opsporing en vervolging van strafbare feiten;"
- d. "de inspectie, controle en toezicht door bestuursorganen;"
- e. "de eerbiediging van de persoonlijke levenssfeer;"
- f. "de bescherming van andere dan in het eerste lid, onderdeel c, genoemde
  concurrentiegevoelige bedrijfs- en fabricagegegevens;"
- g. "de bescherming van het milieu waarop deze informatie betrekking heeft;"
- h. "de beveiliging van personen en bedrijven en het voorkomen van sabotage;"
- i. "het goed functioneren van de Staat, andere publiekrechtelijke lichamen of
  bestuursorganen."

**Artikel 5.1 lid 3** (not a ground): "Indien een verzoek tot openbaarmaking op een van de in het
tweede lid genoemde gronden wordt afgewezen, bevat het besluit hiervoor een uitdrukkelijke
motivering."

**Artikel 5.1 lid 4** (temporary ground): "Openbaarmaking kan tijdelijk achterwege blijven, indien
het belang van de geadresseerde van de informatie om als eerste kennis te nemen van de informatie
dit kennelijk vereist. Het bestuursorgaan doet mededeling aan de verzoeker van de termijn
waarbinnen de openbaarmaking alsnog zal geschieden."

**Artikel 5.1 lid 5** (residual ground): "In uitzonderlijke gevallen kan openbaarmaking van andere
informatie dan milieu-informatie voorts achterwege blijven indien openbaarmaking onevenredige
benadeling toebrengt aan een ander belang dan genoemd in het eerste of tweede lid en het algemeen
belang van openbaarheid niet tegen deze benadeling opweegt. Het bestuursorgaan baseert een
beslissing tot achterwege laten van de openbaarmaking van enige informatie op deze grond ten
aanzien van dezelfde informatie niet tevens op een van de in het eerste of tweede lid genoemde
gronden."

**Artikel 5.1 lid 6** (environmental information): "Het openbaar maken van informatie blijft in
afwijking van het eerste lid, onderdeel c, in geval van milieu-informatie eveneens achterwege voor
zover daardoor het in het eerste lid, onderdeel c, genoemde belang ernstig geschaad wordt en het
algemeen belang van openbaarheid van informatie niet opweegt tegen deze schade."

**Artikel 5.1 lid 7** (not a ground, a limit): "Het eerste en tweede lid zijn niet van toepassing
op milieu-informatie die betrekking heeft op emissies in het milieu."

**Artikel 5.2 lid 1:** "In geval van een verzoek om informatie uit documenten, opgesteld ten
behoeve van intern beraad, wordt geen informatie verstrekt over daarin opgenomen persoonlijke
beleidsopvattingen. Onder persoonlijke beleidsopvattingen worden verstaan ambtelijke adviezen,
visies, standpunten en overwegingen ten behoeve van intern beraad, niet zijnde feiten,
prognoses, beleidsalternatieven, de gevolgen van een bepaald beleidsalternatief of andere
onderdelen met een overwegend objectief karakter."

**Artikel 5.2 lid 2:** "Het bestuursorgaan kan over persoonlijke beleidsopvattingen met het oog op
een goede en democratische bestuursvoering informatie verstrekken in niet tot personen
herleidbare vorm. Indien degene die deze opvattingen heeft geuit of zich erachter heeft gesteld,
daarmee heeft ingestemd, kan de informatie in tot personen herleidbare vorm worden verstrekt."

**Artikel 5.2 lid 3:** "Onverminderd het eerste en tweede lid wordt uit documenten opgesteld ten
behoeve van formele bestuurlijke besluitvorming door een minister, een commissaris van de Koning,
Gedeputeerde Staten, een gedeputeerde, het college van burgemeester en wethouders, een
burgemeester, een wethouder, het dagelijks bestuur van een waterschap of een lid van dat bestuur,
informatie verstrekt over persoonlijke beleidsopvattingen in niet tot personen herleidbare vorm,
tenzij het kunnen voeren van intern beraad onevenredig wordt geschaad."

**Artikel 5.2 lid 4:** "In afwijking van het eerste lid wordt bij milieu-informatie het belang
van de bescherming van de persoonlijke beleidsopvattingen afgewogen tegen het belang van
openbaarmaking. Informatie over persoonlijke beleidsopvattingen kan worden verstrekt in niet tot
personen herleidbare vorm. Indien degene die deze opvattingen heeft geuit of zich erachter heeft
gesteld, daarmee heeft ingestemd, kan de informatie in tot personen herleidbare vorm worden
verstrekt."

**Artikel 5.4** (Formatie): "In afwijking van de artikelen 5.1 en 5.2 is informatie die berust
bij de formateur of de informateur, dan wel informatie die door een bestuursorgaan aan de
formateur of de informateur is gezonden niet openbaar totdat de formatie is afgerond."

**Artikel 5.4a lid 1** (Ondersteuning Kamerleden, statenleden en raadsleden): "In afwijking van de
artikelen 5.1 en 5.2 is niet openbaar de informatie betreffende de ondersteuning van individuele
leden van de Eerste Kamer of de Tweede Kamer der Staten-Generaal, provinciale staten of de
gemeenteraad door ambtenaren werkzaam bij de Eerste Kamer of de Tweede Kamer, de griffie van
provinciale staten of de griffie van de gemeenteraad."

**Artikel 5.4a lid 2** (not a ground, a definition for Kamerleden): "In afwijking van artikel 5.2,
eerste lid, wordt met betrekking tot informatie die aan individuele Kamerleden wordt verstrekt
onder persoonlijke beleidsopvattingen verstaan ambtelijke adviezen, visies, standpunten en
overwegingen ten behoeve van intern beraad."

Articles 5.3 (older than five years: extra motivation), 5.5 (information about the requester),
5.6 (klemmende redenen) and 5.7 (research access) add no ground. They refer back to 5.1 and 5.2.

### 2. The final list

Decided (decision 133): every ground carries `kind`, one of `absolute` (no weighing),
`relative` (weighed against the public interest) or `temporary` (deferral until a moment). Every
entry also carries `citable`: false on the four group nodes 5.1, 5.1.1, 5.1.2 and 5.2, true on all
21 leaves. `validate()` accepts citable leaves only. The draft's fourth value `bijzonder` is
dropped; those grounds are placed as listed in the table (5.2.1 and 5.4a.1 absolute, 5.2.2
relative because lid 2 is a discretionary power, 5.4 temporary because it ends when the formatie
is finished). Those four placements are the coordinator's reading, not Ruben's; flag if wrong.
The REQ-WRG-002 schema gains `kind` and `citable`.

`legalSource` pattern: `https://wetten.overheid.nl/jci1.3:c:BWBR0045754&hoofdstuk=5&artikel=<art>&lid=<lid>`
(add `&onderdeel=<letter>` where a letter applies).

**Group entries** (the tree's broader nodes, see open point 3):

| code | article | lid | letter | label | parent | citable |
| --- | --- | --- | --- | --- | --- | --- |
| 5.1 | 5.1 | | | Uitzonderingen | null | false |
| 5.1.1 | 5.1 | 1 | | Absolute uitzonderingsgronden | 5.1 | false |
| 5.1.2 | 5.1 | 2 | | Relatieve uitzonderingsgronden | 5.1 | false |
| 5.2 | 5.2 | | | Persoonlijke beleidsopvattingen | null | false |

**Citable grounds (21):**

| # | code | article | lid | letter | label | kind | parent | citable |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 5.1.1.a | 5.1 | 1 | a | Eenheid van de Kroon | absolute | 5.1.1 | true |
| 2 | 5.1.1.b | 5.1 | 1 | b | Veiligheid van de Staat | absolute | 5.1.1 | true |
| 3 | 5.1.1.c | 5.1 | 1 | c | Vertrouwelijk meegedeelde bedrijfs- en fabricagegegevens | absolute | 5.1.1 | true |
| 4 | 5.1.1.d | 5.1 | 1 | d | Bijzondere en strafrechtelijke persoonsgegevens | absolute | 5.1.1 | true |
| 5 | 5.1.1.e | 5.1 | 1 | e | Identificatienummers | absolute | 5.1.1 | true |
| 6 | 5.1.2.a | 5.1 | 2 | a | Internationale betrekkingen | relative | 5.1.2 | true |
| 7 | 5.1.2.b | 5.1 | 2 | b | Economische of financiële belangen van de Staat, andere publiekrechtelijke lichamen of bestuursorganen | relative | 5.1.2 | true |
| 8 | 5.1.2.c | 5.1 | 2 | c | Opsporing en vervolging van strafbare feiten | relative | 5.1.2 | true |
| 9 | 5.1.2.d | 5.1 | 2 | d | Inspectie, controle en toezicht | relative | 5.1.2 | true |
| 10 | 5.1.2.e | 5.1 | 2 | e | Eerbiediging van de persoonlijke levenssfeer | relative | 5.1.2 | true |
| 11 | 5.1.2.f | 5.1 | 2 | f | Concurrentiegevoelige bedrijfs- en fabricagegegevens | relative | 5.1.2 | true |
| 12 | 5.1.2.g | 5.1 | 2 | g | Bescherming van het milieu | relative | 5.1.2 | true |
| 13 | 5.1.2.h | 5.1 | 2 | h | Beveiliging van personen en bedrijven | relative | 5.1.2 | true |
| 14 | 5.1.2.i | 5.1 | 2 | i | Goed functioneren van de Staat, andere publiekrechtelijke lichamen of bestuursorganen | relative | 5.1.2 | true |
| 15 | 5.1.4 | 5.1 | 4 | | Geadresseerde neemt eerst kennis | temporary | 5.1 | true |
| 16 | 5.1.5 | 5.1 | 5 | | Onevenredige benadeling | relative | 5.1 | true |
| 17 | 5.1.6 | 5.1 | 6 | | Milieu-informatie, ernstige schade aan bedrijfsgegevens | relative | 5.1 | true |
| 18 | 5.2.1 | 5.2 | 1 | | Persoonlijke beleidsopvattingen in stukken voor intern beraad | absolute | 5.2 | true |
| 19 | 5.2.2 | 5.2 | 2 | | Tot personen herleidbare beleidsopvattingen | relative | 5.2 | true |
| 20 | 5.4 | 5.4 | | | Informatie bij de formateur of informateur | temporary | null | true |
| 21 | 5.4a.1 | 5.4a | 1 | | Ondersteuning van individuele volksvertegenwoordigers | absolute | null | true |

Labels follow the law's wording (decision 133): 7 and 14 carry "de Staat, andere publiekrechtelijke
lichamen of bestuursorganen" as the article says; label 3 keeps "meegedeeld" wording of 5.1 lid 1 c.

Rows 1 to 19 are exactly filinq's 19, with the same article, lid and letter. Rows 20 and 21 are
new (open point 1).

**Rules from the law that are not grounds but that `validate()` could enforce** (open point 4):

- R1 (5.1 lid 5): 5.1.5 is never combined with any 5.1.1.x or 5.1.2.x on the same information.
- R2 (5.1 lid 5): 5.1.5 does not apply to milieu-informatie.
- R3 (5.1 lid 6): 5.1.6 applies only to milieu-informatie, and replaces 5.1.1.c there.
- R4 (5.1 lid 7): no 5.1.1.x or 5.1.2.x for milieu-informatie on emissies.
- R5 (5.1 lid 3): a refusal on 5.1.2.x needs an explicit motivation.
- R6 (5.1 lid 4): 5.1.4 needs the date by which disclosure will follow.

### 3. Mapping from the four fleet lists

#### filinq, schema `base` (19): one to one

| old slug | old name | new code |
| --- | --- | --- |
| art-5-1-1-a to art-5-1-1-e | A to E | 5.1.1.a to 5.1.1.e |
| art-5-1-2-a to art-5-1-2-i | F to N | 5.1.2.a to 5.1.2.i |
| art-5-1-4 | O, Geadresseerde eerst kennisnemen | 5.1.4 |
| art-5-1-5 | P, Onevenredige benadeling | 5.1.5 |
| art-5-1-6 | Q, Milieu-informatie, ernstige schade | 5.1.6 |
| art-5-2-1 | R, Persoonlijke beleidsopvattingen | 5.2.1 |
| art-5-2-2 | S, Tot personen herleidbare beleidsopvattingen | 5.2.2 |

Dropped: none. Missing in old list: 5.4, 5.4a.1. filinq's names carry a letter prefix (A to S)
and its descriptions carry em-dashes; the new labels drop both. filinq's descriptions are good
starting text for `description`.

#### opencatalogi `WooService::WEIGERINGSGRONDEN` (15)

| old code | old label | new code |
| --- | --- | --- |
| 5.1.1.a | Eenheid van de Kroon | 5.1.1.a |
| 5.1.1.b | Veiligheid van de Staat | 5.1.1.b |
| 5.1.1.c | Vertrouwelijk verstrekte bedrijfs- en fabricagegegevens | 5.1.1.c |
| 5.1.1.d | Bijzondere persoonsgegevens en strafrechtelijke gegevens | 5.1.1.d |
| 5.1.2.a | Betrekkingen van Nederland met andere staten ... | 5.1.2.a |
| 5.1.2.b | Economische of financiele belangen van de Staat | 5.1.2.b |
| 5.1.2.c | Opsporing en vervolging van strafbare feiten | 5.1.2.c |
| 5.1.2.d | Inspectie, controle en toezicht door bestuursorganen | 5.1.2.d |
| 5.1.2.e | Eerbiediging van de persoonlijke levenssfeer | 5.1.2.e |
| 5.1.2.f | Bescherming van het milieu | **5.1.2.g** (same code string, other meaning) |
| 5.1.2.g | Beveiliging van personen en bedrijven ... | **5.1.2.h** (same code string, other meaning) |
| 5.1.2.h | Het goed functioneren van de Staat ... | **5.1.2.i** (same code string, other meaning) |
| 5.1.2.i | Het belang van de geadresseerde om als eerste kennis te kunnen nemen | **5.1.4** (same code string, other meaning) |
| 5.2.a | Persoonlijke beleidsopvattingen in documenten voor intern beraad | 5.2.1 |
| 5.2.e | Persoonlijke beleidsopvattingen | 5.2.1, ambiguous (open point 5) |

Missing in old list: 5.1.1.e, 5.1.2.f, 5.1.5, 5.1.6, 5.2.2, 5.4, 5.4a.1.

#### dossiq spec, "Mandatory weigeringsgrond" (12)

| old code | old label | new code |
| --- | --- | --- |
| 5.1.1a | Eenheid van de Kroon | 5.1.1.a |
| 5.1.1b | Veiligheid van de Staat | 5.1.1.b |
| 5.1.2a | Internationale betrekkingen | 5.1.2.a |
| 5.1.2b | Economische of financiele belangen van de Staat | 5.1.2.b |
| 5.1.2c | Opsporing en vervolging van strafbare feiten | 5.1.2.c |
| 5.1.2d | Inspectie, controle en toezicht | 5.1.2.d |
| 5.1.2e | Eerbiediging van de persoonlijke levenssfeer | 5.1.2.e |
| 5.1.2f | Vertrouwelijk verstrekte bedrijfs- en fabricagegegevens | **5.1.1.c** (label decides; open point 5) |
| 5.1.2g | Onevenredige bevoordeling of benadeling | **5.1.5** (old Wob 10.2.g wording; the Woo dropped "bevoordeling") |
| 5.1.2i | Goed functioneren van de Staat | 5.1.2.i |
| 5.2.1 | Persoonlijke beleidsopvattingen | 5.2.1 |
| 5.2.2 | Persoonlijke beleidsopvattingen (intern beraad, geanonimiseerd mogelijk) | 5.2.2 |

Missing in old list: 5.1.1.d, 5.1.1.e, 5.1.2.f, 5.1.2.g, 5.1.2.h, 5.1.4, 5.1.6, 5.4, 5.4a.1.
This is prose only; nothing stores these codes. Task 4.3 replaces the scenario.

#### dossiq code `VALID_WEIGERINGSGRONDEN` + `woo-verzoek.json` (10)

This is the list stored on real `wooDocumentAssessment` objects, so this mapping feeds the repair
step (task 5.1).

| old code | old label | new code |
| --- | --- | --- |
| 5.1.1 | Eenheid van de Kroon | 5.1.1.a |
| 5.1.2 | Veiligheid van de Staat | 5.1.1.b |
| 5.1.3 | Vertrouwelijk verstrekte bedrijfs- en fabricagegegevens | 5.1.1.c |
| 5.1.4 | Persoonlijke beleidsopvattingen | 5.2.1 |
| 5.1.5 | Persoonlijke levenssfeer | 5.1.2.e |
| 5.2.1 | Economische of financiele belangen van de Staat | 5.1.2.b |
| 5.2.2 | Opsporing en vervolging van strafbare feiten | 5.1.2.c |
| 5.2.3 | Inspectie, controle en toezicht door bestuursorganen | 5.1.2.d |
| 5.2.4 | Vertrouwelijkheid van beraadslaging | **5.2.1** (decided by Ruben: maps, not flagged) |
| 5.2.5 | Het goed functioneren van de Staat | 5.1.2.i |

Missing in old list: 5.1.1.d, 5.1.1.e, 5.1.2.a, 5.1.2.f, 5.1.2.g, 5.1.2.h, 5.1.4, 5.1.5, 5.1.6,
5.2.2, 5.4, 5.4a.1.

**Collision warning for task 5.1 (decided: the repair uses a per-object marker).** Old codes 5.1.1, 5.1.2, 5.1.4, 5.1.5, 5.2.1 and 5.2.2 are
also NEW codes, with different meanings (5.1.1 and 5.1.2 become group nodes; 5.1.4, 5.1.5, 5.2.1,
5.2.2 become other grounds). A value-based repair is therefore not idempotent: a second run would
map a new 5.2.1 to 5.1.2.b. The repair uses a per-object marker, so it runs once safely (for example a
`groundsListVersion` field, or recording the mapping run in app config and mapping only objects
last written before it), not "already looks like a new code". The same holds for opencatalogi's
5.1.2.f to 5.1.2.i when its consumer change maps stored values.

### 4. Decisions on the open points (Ruben, 9 Oct 2026, decision 133; the rest are coordinator defaults)

DECIDED summary: (1) include 5.4 and 5.4a lid 1, 21 grounds. (2) add `kind` absolute / relative /
temporary. (3) add `citable`, false on 5.1, 5.1.1, 5.1.2, 5.2; `validate()` accepts citable leaves
only. (4) R1 to R6 and other `validate()` rules: follow-up change (default). (5) keep 5.2.2 as its
own ground, as filinq (default). (6) stored `5.2.4` maps to 5.2.1, not flagged (Ruben). (7) labels
follow the law's wording exactly (default). (8) verbatim check done, below. Task 5.1 repair: per-object
marker (default). The original discussion follows unchanged.

1. **5.4 and 5.4a lid 1: include or not?** They are not in any fleet list. They make information
   "niet openbaar" outside 5.1 and 5.2, so a refusal on them cites them. 5.4a lid 1 matters to
   municipalities (griffie support of raadsleden, decidiq territory). Recommendation: include
   (21). Without them the list is filinq's 19.
2. **The schema has no field for absolute, relative, temporary or own regime.** REQ-WRG-002
   lists code, article, paragraph, letter, label, description, parent, status, legalSource. The
   kind drives the motivation duty (5.1 lid 3) and the five-year rule (5.3). Recommendation: add
   `kind` (`absoluut|relatief|tijdelijk|bijzonder`).
3. **Group nodes are not grounds.** 5.1, 5.1.1, 5.1.2 and 5.2 exist for the tree (REQ-WRG-002's
   parent chain). The schema has no way to say "not citable", so `validate()` would accept 5.1.2
   as a ground. Recommendation: add `citable: boolean` (false on the four group nodes), or
   validate only leaves.
4. **Law rules R1 to R6** (section 2) are cheap checks with real legal weight, especially R1
   (5.1.5 never together with a 5.1 lid 1 or 2 ground on the same information). Not in this
   change's scope. Recommendation: a follow-up change, not this one.
5. **5.2 lid 2 as its own ground (5.2.2).** Lid 2 is a power to disclose in non-traceable form,
   not a refusal. In practice decisions cite it for the names left out of disclosed opinions, and
   filinq already carries it. Recommendation: keep, as filinq has it. Related ambiguity:
   opencatalogi `5.2.e` ("Persoonlijke beleidsopvattingen") duplicates `5.2.a`; mapped to 5.2.1,
   but it may have meant 5.2.2. And dossiq spec `5.1.2f` is labelled as 5.1 lid 1 c but coded
   under lid 2; mapped by label to 5.1.1.c, though 5.1.2.f (concurrentiegevoelig) is possible.
6. **dossiq code `5.2.4` "Vertrouwelijkheid van beraadslaging"** has no Woo counterpart. The
   repair should flag stored uses, not map them. Recommendation: flag and list for a person.
7. **Labels "overheid" vs "Staat"** for 5.1.2.b and 5.1.2.i (section 2 note). Your call on
   wording.
8. **Verbatim check.** Done 9 Oct 2026, see the "Verbatim check" section below.

### 5. Other places in the fleet (outside the four lists, for the consumers)

- **decidiq** `lib/Settings/profiles/municipality.json`, schema `geheimhouding-grond`: four Woo
  rows next to Gemeentewet grounds. "Eenheid van de Kroon / veiligheid van de Staat" is 5.1.1.a
  and 5.1.1.b in one row (split needed); "Vertrouwelijk verstrekte bedrijfs- en
  fabricagegegevens" is 5.1.1.c; "Eerbiediging van de persoonlijke levenssfeer" is 5.1.2.e;
  "Financiële en economische belangen van het bestuursorgaan" is 5.1.2.b. It cites only "Woo art.
  5.1 lid 1/2", no letters. Not a refusal-ground list but a geheimhouding list; worth a
  follow-up to reference dossiq's codes.
- **portaliq** spec `publication-error-reports-and-withheld-notices` uses 5.1.2.e and 5.2.1:
  consistent with this list.
- **integriq**: no list.

### 6. Verbatim check (9 Oct 2026)

Method: downloaded https://wetten.overheid.nl/BWBR0045754/2026-08-15 (official consolidated text,
version geldend van 15-08-2026) as raw HTML, extracted the text of articles 5.1 to 5.4a, and
compared every quoted string in section 1 programmatically (whitespace-normalised) and by eye.
The summarising WebFetch output for 5.2, 5.3, 5.4 and 5.4a agreed with the raw text. No second
source (officielebekendmakingen) was needed because the primary source was read directly.

| Article | URL | Result |
| --- | --- | --- |
| 5.1 lid 1 to 7 | https://wetten.overheid.nl/BWBR0045754/2026-08-15#Hoofdstuk5_Artikel5.1 | identical |
| 5.2 lid 1 to 4 | https://wetten.overheid.nl/BWBR0045754/2026-08-15#Hoofdstuk5_Artikel5.2 | identical |
| 5.3 | https://wetten.overheid.nl/BWBR0045754/2026-08-15#Hoofdstuk5_Artikel5.3 | not quoted in full in the draft; the paraphrase is accurate (refers to 5.1 lid 2 or 5, and 5.2) |
| 5.4 | https://wetten.overheid.nl/BWBR0045754/2026-08-15#Hoofdstuk5_Artikel5.4 | identical |
| 5.4a lid 1 | https://wetten.overheid.nl/BWBR0045754/2026-08-15#Hoofdstuk5_Artikel5.4a | corrected: "informatie betreffend" is "informatie betreffende" |
| 5.4a lid 2 | same | identical |

Not unverifiable: none. Articles 5.5 to 5.7 are described, not quoted, and were not re-checked
word for word.

## D-3. The cross-app call

dossiq side:

```php
namespace OCA\Dossiq\Woo;

final class WooRefusalGrounds {
    /**
     * @return list<array{id: string, code: string, article: string, paragraph: string,
     *   letter: string, label: string, description: string, parent: string|null,
     *   status: 'active'|'retired', legalSource: string,
     *   kind: 'absolute'|'relative'|'temporary'|null, citable: bool}>
     */
    public function list(bool $includeRetired = false): array;

    /** @return array{...same keys...}|null */
    public function byCode(string $code): ?array;
}
```

The read runs as the system, because filinq and opencatalogi call it from background jobs. It
answers active entries in code order. When the register is unreadable it throws
`WooRefusalGroundsUnavailable`. It does not answer an empty list, because an empty list would let
a caller think no grounds exist.

Consumer side (in the consumer's own change): resolve `Woo\WooRefusalGrounds` through the fleet-id
helper for canonical `dossiq`. When it does not resolve, or throws
`WooRefusalGroundsUnavailable`, read the vendored snapshot read-only and mark the result
`source: snapshot`.

## D-4. The snapshot

`lib/Settings/woo-refusal-grounds.snapshot.json` has the form
`{version, generatedAt, source: 'dossiq', grounds: [same keys as list()]}`.
`composer snapshot:refusal-grounds` writes it from the seed in `register.d/`, and
`composer check:refusal-grounds-snapshot` fails when the two differ. That check runs inside
`check:strict`.
