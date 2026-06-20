<?php

/**
 * Procest Range Stream Response
 *
 * A Nextcloud AppFramework Response that serves file content with HTTP Range
 * support, used by the ZGW DRC-compatible download endpoint for resumable
 * transfers of large documents. When the request carries a satisfiable
 * `Range: bytes=start-end` header the response status is 206 Partial Content
 * with a `Content-Range` header and only the requested byte slice in the body;
 * otherwise the full content is returned with `Accept-Ranges: bytes`.
 *
 * @category Http
 * @package  OCA\Procest\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;

/**
 * Range-aware download response.
 *
 * @template-extends Response<int, array<string, mixed>>
 */
class RangeStreamResponse extends Response
{

    /**
     * The (possibly sliced) body to emit.
     *
     * @var string
     */
    private string $body;

    /**
     * Constructor.
     *
     * @param string $content     The full file content.
     * @param string $fileName    The download filename.
     * @param string $contentType The MIME type.
     * @param string $rangeHeader The raw `Range` request header (may be empty).
     */
    public function __construct(string $content, string $fileName, string $contentType, string $rangeHeader='')
    {
        parent::__construct();

        $total = strlen($content);
        $this->addHeader('Accept-Ranges', 'bytes');
        $this->addHeader('Content-Type', $contentType);
        $this->addHeader('Content-Disposition', 'attachment; filename="'.rawurlencode($fileName).'"');

        $range = $this->parseRange(rangeHeader: $rangeHeader, total: $total);

        if ($range === null) {
            $this->body = $content;
            $this->addHeader('Content-Length', (string) $total);
            $this->setStatus(Http::STATUS_OK);
            return;
        }

        [$start, $end] = $range;
        $length        = (($end - $start) + 1);
        $this->body    = substr($content, $start, $length);
        $this->addHeader('Content-Range', 'bytes '.$start.'-'.$end.'/'.$total);
        $this->addHeader('Content-Length', (string) $length);
        $this->setStatus(Http::STATUS_PARTIAL_CONTENT);
    }//end __construct()

    /**
     * Render the response body.
     *
     * @return string The (possibly sliced) content.
     */
    public function render(): string
    {
        return $this->body;
    }//end render()

    /**
     * Parse a single-range `Range: bytes=start-end` header.
     *
     * Returns null when no range is requested or the range is unsatisfiable
     * (caller then serves the full body with status 200).
     *
     * @param string $rangeHeader The raw Range header.
     * @param int    $total       The total content length.
     *
     * @return array{0:int,1:int}|null The clamped [start, end] pair, or null.
     */
    private function parseRange(string $rangeHeader, int $total): ?array
    {
        if ($rangeHeader === '' || $total === 0) {
            return null;
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches) !== 1) {
            return null;
        }

        $startRaw = $matches[1];
        $endRaw   = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            return null;
        }

        if ($startRaw === '') {
            // Suffix range: last N bytes.
            $suffix = (int) $endRaw;
            if ($suffix <= 0) {
                return null;
            }

            $start = max(0, ($total - $suffix));
            $end   = ($total - 1);
        } else {
            $start = (int) $startRaw;
            $end   = $endRaw === '' ? ($total - 1) : (int) $endRaw;
        }

        if ($start > $end || $start >= $total) {
            return null;
        }

        $end = min($end, ($total - 1));

        return [$start, $end];
    }//end parseRange()
}//end class
