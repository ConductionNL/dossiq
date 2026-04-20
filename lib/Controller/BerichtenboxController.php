<?php

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\BerichtenboxService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class BerichtenboxController extends Controller
{
    public function __construct(
        IRequest $request,
        private BerichtenboxService $berichtenboxService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
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
     * @NoAdminRequired
     */
    public function messages(): JSONResponse
    {
        $caseId   = $this->request->getParam('caseId', '');
        $messages = $this->berichtenboxService->getMessagesForCase($caseId);
        return new JSONResponse(['success' => true, 'messages' => $messages]);
    }//end messages()

    /**
     * @NoAdminRequired
     */
    public function poll(string $messageId): JSONResponse
    {
        $result = $this->berichtenboxService->pollReadStatus($messageId);
        return new JSONResponse(['success' => true, 'message' => $result]);
    }//end poll()
}//end class
