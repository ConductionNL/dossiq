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

## D-2. The settlement (filled in by task 1)

To be written by whoever settles it: the date, the source version of the Woo, the final list with
code, article, paragraph, letter and label, and the mapping from each of the four lists above to
it. Mark every entry of the old lists that maps to nothing.

## D-3. The cross-app call

dossiq side:

```php
namespace OCA\Dossiq\Woo;

final class WooRefusalGrounds {
    /**
     * @return list<array{id: string, code: string, article: string, paragraph: string,
     *   letter: string, label: string, description: string, parent: string|null,
     *   status: 'active'|'retired', legalSource: string}>
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
