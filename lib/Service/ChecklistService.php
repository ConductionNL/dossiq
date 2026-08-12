<?php

/**
 * Procest Checklist Service (per-run progress and conformity)
 *
 * REQ-003 of openspec/specs/inspection-checklists/spec.md: the PURE half of
 * inspection checklist handling. Every method here is a function of the payload
 * it is handed — no database, no OpenRegister, no clock, no user session — so
 * the same calculation runs server-side, in a dry-run preview, and in a unit
 * test with identical results.
 *
 * ⚠️ This is deliberately NOT the same class as
 * `lib/Service/Inspection/ChecklistService.php`. The spec's own Notes block
 * keeps them apart on purpose: the namespaced one owns the TEMPLATE lifecycle
 * (snapshotting, run creation, submission, follow-up dispatch) and does I/O;
 * this top-level one owns per-run PROGRESS arithmetic and does none.
 * Consolidation is explicitly deferred there, so merging them would contradict
 * the spec rather than tidy it.
 *
 * Answer conventions match `Inspection\ChecklistService` exactly, so the two
 * never disagree about which answer is a failure:
 *   - `responseType: ja_nee_nvt` → `nvt` is not-applicable, `nee` is
 *     non-conforming, anything else conforms;
 *   - `fotoRequired` is the tri-state `nooit` / `bij_nee` / `altijd`, not a
 *     boolean.
 * Payload SHAPE rules (flat vs sectioned items, `templateSnapshot`, item ids)
 * live in `ChecklistPayloadReader` so they are stated once.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\Support\ChecklistPayloadReader;
use RuntimeException;

/**
 * Pure per-run checklist arithmetic: completion, progress and conformity.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 */
class ChecklistService
{

    /**
     * Photo gate: a photo is never required for this item.
     *
     * @var string
     */
    public const PHOTO_NEVER = 'nooit';

    /**
     * Photo gate: a photo is required only when the answer is 'nee'.
     *
     * @var string
     */
    public const PHOTO_ON_FAIL = 'bij_nee';

    /**
     * Photo gate: a photo is always required once the item is answered.
     *
     * @var string
     */
    public const PHOTO_ALWAYS = 'altijd';

    /**
     * Response type whose values are ja / nee / nvt.
     *
     * @var string
     */
    private const TYPE_JA_NEE_NVT = 'ja_nee_nvt';

    /**
     * Reads items and responses out of a payload.
     *
     * @var ChecklistPayloadReader
     */
    private ChecklistPayloadReader $reader;

    /**
     * Constructor.
     *
     * The reader is constructed rather than injected because it is a pure
     * shape helper with no dependencies of its own; keeping it out of the
     * container preserves REQ-003's "no I/O, constructible in a unit test"
     * property.
     *
     * @spec openspec/specs/inspection-checklists/spec.md
     */
    public function __construct()
    {
        $this->reader = new ChecklistPayloadReader();
    }//end __construct()

    /**
     * Merge one item's answer into a checklist payload.
     *
     * Pure: the argument is not mutated. The updated payload is returned, so a
     * caller can chain completions and only then decide whether to persist.
     *
     * ⚠️ The photo rule is enforced HERE as well as in `validateCompletion()`,
     * because REQ-001 requires `completeChecklistItem` to answer 400 and leave
     * the item INCOMPLETE when a mandatory photo is missing. Validating only at
     * submission time would accept the answer and reject the run later, which
     * is not what that scenario asks for.
     *
     * @param array<string, mixed> $checklist The run payload (templateSnapshot + responses).
     * @param string               $itemId    The item being answered.
     * @param array<string, mixed> $response  The answer: value, comment, photos, ...
     *
     * @return array<string, mixed> A new payload with the response merged in.
     *
     * @throws RuntimeException When the item is unknown, or a mandatory photo is absent.
     *
     * @spec openspec/specs/inspection-checklists/spec.md
     */
    public function completeItem(array $checklist, string $itemId, array $response): array
    {
        $items = $this->reader->items(checklist: $checklist);
        if (isset($items[$itemId]) === false) {
            throw new RuntimeException('Unknown checklist item: '.$itemId);
        }

        $response['itemId'] = $itemId;

        $violation = $this->photoViolation(item: $items[$itemId], response: $response);
        if ($violation !== null) {
            throw new RuntimeException($violation);
        }

        $responses = [];
        $replaced  = false;
        foreach ($this->reader->responses(checklist: $checklist) as $existing) {
            if ((string) ($existing['itemId'] ?? '') === $itemId) {
                $responses[] = $response;
                $replaced    = true;
                continue;
            }

            $responses[] = $existing;
        }

        if ($replaced === false) {
            $responses[] = $response;
        }

        $checklist['responses'] = $responses;

        return $checklist;
    }//end completeItem()

    /**
     * Count answered items against the total.
     *
     * `percent` is an integer 0–100, rounded half up. A checklist with no items
     * is 100% complete rather than a division by zero — there is nothing left
     * to do, which is what every caller means by "complete".
     *
     * @param array<string, mixed> $checklist The run payload.
     *
     * @return array{completed: int, total: int, percent: int} The progress.
     *
     * @spec openspec/specs/inspection-checklists/spec.md
     */
    public function getProgress(array $checklist): array
    {
        $items    = $this->reader->items(checklist: $checklist);
        $byItemId = $this->reader->responsesByItemId(checklist: $checklist);
        $total    = count($items);

        $completed = 0;
        foreach (array_keys($items) as $itemId) {
            $response = ($byItemId[$itemId] ?? null);
            if ($response !== null && $this->reader->isAnswered(response: $response) === true) {
                $completed++;
            }
        }

        $percent = 100;
        if ($total > 0) {
            $percent = (int) round((($completed / $total) * 100));
        }

        return [
            'completed' => $completed,
            'total'     => $total,
            'percent'   => $percent,
        ];
    }//end getProgress()

    /**
     * Check the run may be submitted: every required item answered, every
     * mandatory photo attached.
     *
     * Returns the list of violations rather than throwing on the first one, so
     * the inspector sees everything still outstanding in a single response
     * instead of discovering it one field at a time.
     *
     * @param array<string, mixed> $checklist The run payload.
     *
     * @return array<int, string> Human-readable violations; empty means valid.
     *
     * @spec openspec/specs/inspection-checklists/spec.md
     */
    public function validateCompletion(array $checklist): array
    {
        $items      = $this->reader->items(checklist: $checklist);
        $byItemId   = $this->reader->responsesByItemId(checklist: $checklist);
        $violations = [];

        foreach ($items as $itemId => $item) {
            $response = ($byItemId[$itemId] ?? null);
            if ($response === null || $this->reader->isAnswered(response: $response) === false) {
                if (($item['required'] ?? false) === true) {
                    $violations[] = 'Required item not answered: '
                        .$this->reader->label(item: $item, itemId: (string) $itemId);
                }

                continue;
            }

            $violation = $this->photoViolation(item: $item, response: $response);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }//end validateCompletion()

    /**
     * Count how the answered items came out, and how many are still pending.
     *
     * `pending` counts items with no usable answer, so
     * `conforming + nonConforming + na + pending` always equals the item total.
     *
     * @param array<string, mixed> $checklist The run payload.
     *
     * @return array{conforming: int, nonConforming: int, na: int, pending: int} The summary.
     *
     * @spec openspec/specs/inspection-checklists/spec.md
     */
    public function getConformitySummary(array $checklist): array
    {
        $items    = $this->reader->items(checklist: $checklist);
        $byItemId = $this->reader->responsesByItemId(checklist: $checklist);

        $summary = [
            'conforming'    => 0,
            'nonConforming' => 0,
            'na'            => 0,
            'pending'       => 0,
        ];

        foreach ($items as $itemId => $item) {
            $response = ($byItemId[$itemId] ?? null);
            if ($response === null || $this->reader->isAnswered(response: $response) === false) {
                $summary['pending']++;
                continue;
            }

            $summary[$this->classify(item: $item, response: $response)]++;
        }

        return $summary;
    }//end getConformitySummary()

    /**
     * Classify one answered item as conforming, non-conforming or not-applicable.
     *
     * Only `ja_nee_nvt` carries an intrinsic verdict; every other response type
     * records a measurement or a note and conforms by virtue of being answered.
     * Range checking for `getal` / `meting` belongs to
     * `Inspection\ChecklistService::validateResponse()`, which rejects an
     * out-of-range value outright, so such a value never reaches this method.
     *
     * @param array<string, mixed> $item     The frozen item definition.
     * @param array<string, mixed> $response The submitted answer.
     *
     * @return string One of `conforming`, `nonConforming`, `na`.
     *
     * @spec openspec/specs/inspection-checklists/spec.md
     */
    private function classify(array $item, array $response): string
    {
        if ((string) ($item['responseType'] ?? '') !== self::TYPE_JA_NEE_NVT) {
            return 'conforming';
        }

        return match ((string) ($response['value'] ?? '')) {
            'nvt'   => 'na',
            'nee'   => 'nonConforming',
            default => 'conforming',
        };
    }//end classify()

    /**
     * Describe the photo rule this response breaks, if any.
     *
     * @param array<string, mixed> $item     The frozen item definition.
     * @param array<string, mixed> $response The submitted answer.
     *
     * @return string|null The violation, or null when the rule is satisfied.
     *
     * @spec openspec/specs/inspection-checklists/spec.md
     */
    private function photoViolation(array $item, array $response): ?string
    {
        $gate = (string) ($item['fotoRequired'] ?? self::PHOTO_NEVER);
        if ($gate === self::PHOTO_NEVER || $gate === '') {
            return null;
        }

        if ($gate === self::PHOTO_ON_FAIL && (string) ($response['value'] ?? '') !== 'nee') {
            return null;
        }

        $photos = ($response['photos'] ?? []);
        if (is_array($photos) === true && $photos !== []) {
            return null;
        }

        return 'A photo is required for: '.$this->reader->label(
            item: $item,
            itemId: (string) ($response['itemId'] ?? '')
        );
    }//end photoViolation()
}//end class
