<?php

/**
 * Procest Appointment Controller.
 *
 * REST endpoints for citizen appointment scheduling flows (list, create,
 * cancel, mark no-show, query timeslots).
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
 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AppointmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller exposing citizen appointment endpoints.
 */
class AppointmentController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request            The request object.
     * @param AppointmentService $appointmentService The appointment service.
     */
    public function __construct(
        IRequest $request,
        private AppointmentService $appointmentService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List appointments scheduled for a case.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        $caseId       = $this->request->getParam('caseId');
        $appointments = $this->appointmentService->getAppointmentsForCase($caseId ?? '');
        return new JSONResponse(['success' => true, 'appointments' => $appointments]);
    }//end index()

    /**
     * Book a new citizen appointment for a case.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function create(): JSONResponse
    {
        $caseId = $this->request->getParam('caseId');
        if (empty($caseId) === true) {
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
     * Cancel an existing appointment.
     *
     * @param string $appointmentId The appointment UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function cancel(string $appointmentId): JSONResponse
    {
        $result = $this->appointmentService->cancelAppointment($appointmentId);
        return new JSONResponse(['success' => true, 'appointment' => $result]);
    }//end cancel()

    /**
     * Mark an appointment as a no-show.
     *
     * @param string $appointmentId The appointment UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function noShow(string $appointmentId): JSONResponse
    {
        $result = $this->appointmentService->markNoShow($appointmentId);
        return new JSONResponse(['success' => true, 'appointment' => $result]);
    }//end noShow()

    /**
     * List available timeslots for a product/location/date combination.
     *
     * @return JSONResponse
     *
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
