<?php

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AppointmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class PublicAppointmentController extends Controller
{
    public function __construct(
        IRequest $request,
        private AppointmentService $appointmentService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function view(string $token): JSONResponse
    {
        $appointment = $this->appointmentService->getAppointmentByToken($token);
        if ($appointment === null) {
            return new JSONResponse(['error' => 'Afspraak niet gevonden'], 404);
        }

        return new JSONResponse([
            'success'     => true,
            'appointment' => [
                'dateTime'    => $appointment['dateTime'] ?? null,
                'duration'    => $appointment['duration'] ?? 30,
                'status'      => $appointment['status'] ?? 'scheduled',
                'locationId'  => $appointment['locationId'] ?? null,
                'productId'   => $appointment['productId'] ?? null,
            ],
        ]);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function cancel(string $token): JSONResponse
    {
        $appointment = $this->appointmentService->getAppointmentByToken($token);
        if ($appointment === null) {
            return new JSONResponse(['error' => 'Afspraak niet gevonden'], 404);
        }

        if ($appointment['status'] === 'cancelled') {
            return new JSONResponse(['error' => 'Afspraak is al geannuleerd'], 400);
        }

        $id     = $appointment['uuid'] ?? $appointment['id'] ?? '';
        $result = $this->appointmentService->cancelAppointment($id);
        return new JSONResponse(['success' => true, 'appointment' => $result]);
    }
}
