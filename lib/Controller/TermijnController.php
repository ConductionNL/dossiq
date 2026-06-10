<?php

/**
 * Procest TermijnController.
 *
 * REST surface for TermijnInstance lifecycle (create, get, pauze,
 * hervat, verleng, voltooi). Defers all business logic to
 * {@see TermijnService}, {@see TermijnPauseService} and
 * {@see TermijnExtensionService} (ADR-022).
 *
 * Auth: @NoAdminRequired — handler / caseworker calls only. Per-object
 * IDOR guard is enforced by re-fetching the instance and verifying the
 * caller's case-access through the existing CaseSharingService is
 * outside the chain scope; for now we rely on NC SecurityMiddleware's
 * authenticated-user default + the case-bound zaakId on the row.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use DateTimeImmutable;
use OCA\Procest\Service\TermijnExtensionService;
use OCA\Procest\Service\TermijnPauseService;
use OCA\Procest\Service\TermijnService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for TermijnInstance lifecycle.
 *
 * @psalm-suppress UnusedClass
 */
class TermijnController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                  $appName    App id.
     * @param IRequest                $request    Request.
     * @param TermijnService          $termijn    Termijn service.
     * @param TermijnPauseService     $pause      Pause service.
     * @param TermijnExtensionService $extension  Extension service.
     * @param LoggerInterface         $logger     Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TermijnService $termijn,
        private readonly TermijnPauseService $pause,
        private readonly TermijnExtensionService $extension,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Create a TermijnInstance for a zaak.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function create(): JSONResponse
    {
        $body     = $this->jsonBody();
        $zaakId   = (string) ($body['zaakId'] ?? '');
        $zaaktype = (string) ($body['zaaktype'] ?? '');
        if ($zaakId === '' || $zaaktype === '') {
            return $this->badRequest('zaakId and zaaktype are required');
        }

        try {
            $row = $this->termijn->createTermijnInstance($zaakId, $zaaktype);
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (Throwable $e) {
            return $this->error($e, 'Termijn create failed');
        }
    }//end create()

    /**
     * Get a TermijnInstance by id.
     *
     * @NoAdminRequired
     *
     * @param string $id Instance id.
     *
     * @return JSONResponse
     */
    public function show(string $id): JSONResponse
    {
        $row = $this->termijn->getTermijnInstance($id);
        if ($row === null) {
            return $this->notFound('TermijnInstance not found: '.$id);
        }
        return new JSONResponse($row);
    }//end show()

    /**
     * Pause a TermijnInstance.
     *
     * @NoAdminRequired
     *
     * @param string $id Instance id.
     *
     * @return JSONResponse
     */
    public function pauze(string $id): JSONResponse
    {
        $body         = $this->jsonBody();
        $duurDagen    = (int) ($body['duurDagen'] ?? 0);
        $motivering   = (string) ($body['motivering'] ?? '');
        $documentLink = (string) ($body['documentLink'] ?? '');

        try {
            $row = $this->pause->registerPauze($id, $duurDagen, $motivering, $documentLink);
            return new JSONResponse($row);
        } catch (Throwable $e) {
            return $this->error($e, 'Pauze failed');
        }
    }//end pauze()

    /**
     * Resume after pauze.
     *
     * @NoAdminRequired
     *
     * @param string $id Instance id.
     *
     * @return JSONResponse
     */
    public function hervat(string $id): JSONResponse
    {
        $body = $this->jsonBody();
        $when = (string) ($body['aanvullingDatum'] ?? '');

        try {
            $row = $this->pause->resumeAfterPauze($id, $when !== '' ? new DateTimeImmutable($when) : null);
            return new JSONResponse($row);
        } catch (Throwable $e) {
            return $this->error($e, 'Hervat failed');
        }
    }//end hervat()

    /**
     * Request a verlenging.
     *
     * @NoAdminRequired
     *
     * @param string $id Instance id.
     *
     * @return JSONResponse
     */
    public function verleng(string $id): JSONResponse
    {
        $body               = $this->jsonBody();
        $motivering         = (string) ($body['motivering'] ?? '');
        $newEinddatum       = (string) ($body['newEinddatum'] ?? '');
        $documentLink       = (string) ($body['documentLink'] ?? '');
        $supervisorOverride = (bool)   ($body['supervisorOverride'] ?? false);

        try {
            $row = $this->extension->requestExtension($id, $motivering, $newEinddatum, $documentLink, $supervisorOverride);
            return new JSONResponse($row);
        } catch (Throwable $e) {
            return $this->error($e, 'Verleng failed');
        }
    }//end verleng()

    /**
     * Mark a TermijnInstance as voltooid.
     *
     * @NoAdminRequired
     *
     * @param string $id Instance id.
     *
     * @return JSONResponse
     */
    public function voltooi(string $id): JSONResponse
    {
        $body         = $this->jsonBody();
        $when         = (string) ($body['voltooiDatum'] ?? '');
        $documentLink = (string) ($body['documentLink'] ?? '');

        try {
            $row = $this->termijn->markTermijnCompleted(
                $id,
                $when !== '' ? new DateTimeImmutable($when) : null,
                $documentLink
            );
            if ($row === null) {
                return $this->notFound('TermijnInstance not found: '.$id);
            }
            return new JSONResponse($row);
        } catch (Throwable $e) {
            return $this->error($e, 'Voltooi failed');
        }
    }//end voltooi()

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = method_exists($this->request, 'getContent') === true
            ? (string) $this->request->getContent()
            : '';
        $body = json_decode($raw, true);
        return is_array($body) === true ? $body : [];
    }

    /**
     * @param string $msg Message.
     * @return JSONResponse
     */
    private function badRequest(string $msg): JSONResponse
    {
        return new JSONResponse(['message' => $msg], Http::STATUS_BAD_REQUEST);
    }

    /**
     * @param string $msg Message.
     * @return JSONResponse
     */
    private function notFound(string $msg): JSONResponse
    {
        return new JSONResponse(['message' => $msg], Http::STATUS_NOT_FOUND);
    }

    /**
     * @param Throwable $e   Exception.
     * @param string    $log Log prefix.
     * @return JSONResponse
     */
    private function error(Throwable $e, string $log): JSONResponse
    {
        $this->logger->info($log.': '.$e->getMessage());
        return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
    }
}//end class
