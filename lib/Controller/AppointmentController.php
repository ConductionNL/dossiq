<?php

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AppointmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class AppointmentController extends Controller
{
    public function __construct(
        IRequest $request,
        private AppointmentService $appointmentService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        $caseId       = $this->request->getParam('caseId');
        $appointments = $this->appointmentService->getAppointmentsForCase($caseId ?? '');
        return new JSONResponse(['success' => true, 'appointments' => $appointments]);
    }//end index()

    /**
     * @NoAdminRequired
     */
    public function create(): JSONResponse
    {
        $caseId = $this->request->getParam('caseId');
        if (empty($caseId)) {
            return new JSONResponse(['success' => false, 'error' => 'caseId required'], 400);
        }

        $data = [
            'productId'    => $this->request->getParam('productId'),
            'locationId'   => $this->request->getParam('locationId'),
            'dateTime'     => $this->request->getParam('dateTime'),
            'duration'     => (int) $this->request->getParam('duration', '30'),
            'citizenName'  => $this->request->getParam('citizenName', ''),
            'citizenEmail' => $this->request->getParam('citizenEmail', ''),
            'citizenPhone' => $this->request->getParam('citizenPhone'),
            'notes'        => $this->request->getParam('notes'),
        ];

        $result = $this->appointmentService->bookAppointment($caseId, $data);
        return new JSONResponse(['success' => true, 'appointment' => $result]);
    }//end create()

    /**
     * @NoAdminRequired
     */
    public function cancel(string $appointmentId): JSONResponse
    {
        $result = $this->appointmentService->cancelAppointment($appointmentId);
        return new JSONResponse(['success' => true, 'appointment' => $result]);
    }//end cancel()

    /**
     * @NoAdminRequired
     */
    public function noShow(string $appointmentId): JSONResponse
    {
        $result = $this->appointmentService->markNoShow($appointmentId);
        return new JSONResponse(['success' => true, 'appointment' => $result]);
    }//end noShow()

    /**
     * @NoAdminRequired
     */
    public function timeslots(): JSONResponse
    {
        $productId  = $this->request->getParam('productId', '');
        $locationId = $this->request->getParam('locationId', '');
        $date       = $this->request->getParam('date', date('Y-m-d'));

        $slots = $this->appointmentService->getTimeslots($productId, $locationId, $date);
        return new JSONResponse(['success' => true, 'timeslots' => $slots]);
    }//end timeslots()
}//end class
