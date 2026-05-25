<?php

/**
 * Procest Berichtenbox Controller.
 *
 * REST endpoints for sending, listing and polling Mijn Overheid Berichtenbox
 * messages linked to cases.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\BerichtenboxService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller exposing Berichtenbox send/list/poll endpoints.
 */
class BerichtenboxController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest            $request             The request object.
     * @param BerichtenboxService $berichtenboxService The Berichtenbox service.
     */
    public function __construct(
        IRequest $request,
        private BerichtenboxService $berichtenboxService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Send a Berichtenbox message for a case.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function send(): JSONResponse
    {
        $caseId   = $this->request->getParam('caseId');
        $bsn      = $this->request->getParam('bsn', '');
        $subject  = $this->request->getParam('subject', '');
        $body     = $this->request->getParam('body', '');
        $typeCode = $this->request->getParam('berichtTypeCode', '');
        $attachmentFileId = $this->request->getParam('attachmentFileId');

        if (empty($caseId) === true) {
            return new JSONResponse(['success' => false, 'error' => 'caseId is required'], 400);
        }

        $result = $this->berichtenboxService->sendMessage(
            $caseId,
                $bsn,
                $subject,
                $body,
                $typeCode,
                $attachmentFileId
        );

        if (isset($result['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $result['error']], 400);
        }

        return new JSONResponse(['success' => true, 'message' => $result]);
    }//end send()

    /**
     * List Berichtenbox messages linked to a case.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function messages(): JSONResponse
    {
        $caseId   = $this->request->getParam('caseId', '');
        $messages = $this->berichtenboxService->getMessagesForCase($caseId);
        return new JSONResponse(['success' => true, 'messages' => $messages]);
    }//end messages()

    /**
     * Poll read-status for a sent Berichtenbox message.
     *
     * @param string $messageId The external message identifier.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function poll(string $messageId): JSONResponse
    {
        $result = $this->berichtenboxService->pollReadStatus($messageId);
        return new JSONResponse(['success' => true, 'message' => $result]);
    }//end poll()
}//end class
