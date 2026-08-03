<?php

/**
 * Procest LibreSign Status Mapper.
 *
 * Pure mapping of LibreSign's signature-request status vocabulary (both the
 * `statusText` string and the legacy numeric `status` code) onto procest's
 * own internal pending/signed/declined/unknown vocabulary used by
 * LibresignSigningAdapter. Deliberately has no I/O so it can be exhaustively
 * unit tested without mocking HTTP.
 *
 * @category Service
 * @package  OCA\Procest\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/libresign-besluit-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

/**
 * Maps LibreSign status values onto procest's internal signing vocabulary.
 *
 * @spec openspec/specs/libresign-besluit-signing/spec.md
 */
class LibresignStatusMapper
{
    /**
     * The request has been created but has not (yet) been fully signed.
     *
     * @var string
     */
    public const PENDING = 'pending';

    /**
     * All required signers have signed.
     *
     * @var string
     */
    public const SIGNED = 'signed';

    /**
     * The request was declined, deleted, or otherwise cancelled.
     *
     * @var string
     */
    public const DECLINED = 'declined';

    /**
     * A LibreSign status value that this mapper does not recognise.
     *
     * @var string
     */
    public const UNKNOWN = 'unknown';

    /**
     * LibreSign `statusText`/`status` values that map to PENDING.
     *
     * @var array<int, string>
     */
    private const PENDING_VALUES = [
        'draft',
        'able_to_sign',
        'partial_signed',
        'pending',
        '0',
        '1',
        '2',
    ];

    /**
     * LibreSign `statusText`/`status` values that map to SIGNED.
     *
     * @var array<int, string>
     */
    private const SIGNED_VALUES = [
        'signed',
        '3',
    ];

    /**
     * LibreSign `statusText`/`status` values that map to DECLINED.
     *
     * @var array<int, string>
     */
    private const DECLINED_VALUES = [
        'deleted',
        'declined',
        'rejected',
        'cancelled',
        '4',
    ];

    /**
     * Map a raw LibreSign status value onto the internal vocabulary.
     *
     * Accepts either LibreSign's `statusText` (preferred, e.g. "signed") or
     * its numeric `status` code stringified (e.g. "3"). Comparison is
     * case-insensitive; an unrecognised value returns {@see self::UNKNOWN}
     * rather than guessing, so callers never optimistically treat an
     * unexpected value as SIGNED.
     *
     * @param string $raw The raw LibreSign status value.
     *
     * @return string One of PENDING, SIGNED, DECLINED, UNKNOWN.
     *
     * @spec openspec/specs/libresign-besluit-signing/spec.md
     */
    public function map(string $raw): string
    {
        $normalised = strtolower(trim($raw));

        if (in_array($normalised, self::SIGNED_VALUES, true) === true) {
            return self::SIGNED;
        }

        if (in_array($normalised, self::DECLINED_VALUES, true) === true) {
            return self::DECLINED;
        }

        if (in_array($normalised, self::PENDING_VALUES, true) === true) {
            return self::PENDING;
        }

        return self::UNKNOWN;
    }//end map()
}//end class
