<?php

/**
 * Procest SuppressesWarnings support trait.
 *
 * A scoped, greppable replacement for the `@` error-control operator.
 *
 * Several PHP core calls (imap_*, dns_get_record, fsockopen,
 * file_get_contents over a stream wrapper, unlink) report failure BOTH by
 * return value and by emitting an E_WARNING. Procest always checks the return
 * value, so the warning is pure noise on an expected, handled outcome — but
 * `@` is the wrong tool for it: it is invisible in a diff, it swallows every
 * severity, and it stays in effect for the whole expression including nested
 * calls.
 *
 * {@see self::withoutWarnings()} installs an error handler for exactly one
 * call, records the suppressed message so the caller can log it, and restores
 * the previous handler in a `finally` block. Fatal errors and exceptions are
 * unaffected.
 *
 * @category Support
 * @package  OCA\Procest\Support
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
 */

declare(strict_types=1);

namespace OCA\Procest\Support;

/**
 * Run a warning-noisy core call with its diagnostics captured instead of
 * printed, without reaching for the `@` operator.
 */
trait SuppressesWarnings
{

    /**
     * Last diagnostic captured by {@see self::withoutWarnings()}.
     *
     * @var string
     */
    private string $suppressedWarning = '';

    /**
     * Run a callable with E_WARNING/E_NOTICE captured rather than emitted.
     *
     * The callable's return value is passed straight through, so the caller
     * keeps its normal failure check. Any diagnostic raised during the call is
     * stored and readable via {@see self::lastSuppressedWarning()} so it can be
     * logged with context instead of vanishing.
     *
     * @param callable $operation The core call to run.
     *
     * @return mixed Whatever $operation returned.
     */
    private function withoutWarnings(callable $operation): mixed
    {
        $this->suppressedWarning = '';

        set_error_handler(
            function (int $severity, string $message): bool {
                // Keep the severity in the recorded text — an E_DEPRECATED and
                // an E_WARNING from the same call mean very different things to
                // whoever reads the log line the caller writes.
                $this->suppressedWarning = '['.$severity.'] '.$message;
                return true;
            },
            (E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING)
        );

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }//end withoutWarnings()

    /**
     * The diagnostic captured by the most recent
     * {@see self::withoutWarnings()} call, or '' when there was none.
     *
     * @return string The captured message.
     */
    private function lastSuppressedWarning(): string
    {
        return $this->suppressedWarning;
    }//end lastSuppressedWarning()
}//end trait
