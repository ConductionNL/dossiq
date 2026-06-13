<?php

/**
 * Procest Scan Expiring Contracts Job.
 *
 * Nightly background job that drives the supplier-contract renewal sweep:
 * lists every `supplierContract`, flags rows whose `endDate` falls within the
 * 90-day renewal window by setting `renewalWarning`, and is idempotent — a
 * second run in the same window writes nothing (already-flagged rows are
 * skipped by {@see ContractRenewalService::scanExpiringContracts()}).
 *
 * Registered via appinfo/info.xml `<background-jobs>`; Nextcloud auto-registers
 * it with the IJobList (no IRegistrationContext::registerJob exists).
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
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
 * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\ContractRenewalService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Nightly timed job: supplier-contract expiry sweep + renewalWarning flagging.
 *
 * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
 */
class ScanExpiringContractsJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory           $time       Time factory.
     * @param ContractRenewalService $renewal    Contract renewal service.
     * @param IAppManager            $appManager App manager.
     * @param LoggerInterface        $logger     Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ContractRenewalService $renewal,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Nightly (~03:00 window). The underlying scan is idempotent if it runs
        // twice, so NC's own scheduling jitter is harmless.
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Run the nightly contract-expiry sweep.
     *
     * @param mixed $argument Unused.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return;
        }

        try {
            $counts = $this->renewal->scanAndFlagExpiring(time());
            $this->logger->info('Procest contract expiry scan finished', $counts);
        } catch (\Throwable $e) {
            $this->logger->error('Procest contract expiry scan failed', ['error' => $e->getMessage()]);
        }
    }//end run()
}//end class
