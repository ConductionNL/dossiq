<?php

/**
 * Procest LHS Lookup Service
 *
 * Pure lookup service for the Landelijke Handhavingsstrategie (LHS) 4x4 matrix.
 * Accepts (gedrag, gevolg) and returns the recommended interventieladder step
 * from the seeded lhsMatrixCell objects.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * LHS 4x4 matrix lookup service.
 *
 * @spec openspec/changes/vth-module/tasks.md#task-8
 */
class LhsLookupService
{

    /**
     * Fallback in-memory matrix (A-D × 1-4) matching the LHS 2022 national standard.
     *
     * Row = gedrag: A=Goedwillend, B=Onverschillig, C=Calculerend, D=Crimineel
     * Col = gevolg (mogelijke gevolgen): 1=Klein, 2=Matig, 3=Groot, 4=Onomkeerbaar
     */
    private const FALLBACK_MATRIX = [
        'A' => [
            '1' => ['interventieStep' => 'Aanspreken / informeren',                 'description' => 'Informeer de overtreder over de regels.'],
            '2' => ['interventieStep' => 'Waarschuwen',                             'description' => 'Stuur een schriftelijke waarschuwing.'],
            '3' => ['interventieStep' => 'Bestuurlijke waarschuwing',               'description' => 'Formele bestuurlijke waarschuwing met hersteltermijn.'],
            '4' => ['interventieStep' => 'Last onder dwangsom',                     'description' => 'Opleggen last onder dwangsom.'],
        ],
        'B' => [
            '1' => ['interventieStep' => 'Waarschuwen',                             'description' => 'Schriftelijke waarschuwing.'],
            '2' => ['interventieStep' => 'Bestuurlijke waarschuwing',               'description' => 'Bestuurlijke waarschuwing met hersteltermijn.'],
            '3' => ['interventieStep' => 'Last onder dwangsom',                     'description' => 'Last onder dwangsom opleggen.'],
            '4' => ['interventieStep' => 'Last onder dwangsom + Proces-verbaal',    'description' => 'Bestuurlijk én strafrechtelijk optreden.'],
        ],
        'C' => [
            '1' => ['interventieStep' => 'Bestuurlijke waarschuwing',               'description' => 'Bestuurlijke waarschuwing.'],
            '2' => ['interventieStep' => 'Last onder dwangsom',                     'description' => 'Last onder dwangsom.'],
            '3' => ['interventieStep' => 'Bestuursdwang + Proces-verbaal',          'description' => 'Bestuursdwang toepassen en aangifte doen.'],
            '4' => ['interventieStep' => 'Bestuursdwang + Proces-verbaal',          'description' => 'Zwaarste bestuurlijke + strafrechtelijke inzet.'],
        ],
        'D' => [
            '1' => ['interventieStep' => 'Last onder dwangsom',                     'description' => 'Last onder dwangsom.'],
            '2' => ['interventieStep' => 'Bestuursdwang + Proces-verbaal',          'description' => 'Bestuursdwang en strafrechtelijk optreden.'],
            '3' => ['interventieStep' => 'Bestuursdwang + Proces-verbaal',          'description' => 'Zwaarste bestuurlijke + strafrechtelijke inzet.'],
            '4' => ['interventieStep' => 'Bestuursdwang + Proces-verbaal',          'description' => 'Maximale inzet bestuur en OM.'],
        ],
    ];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Look up the recommended interventieladder step for a gedrag × gevolg combination.
     *
     * First tries to load from OpenRegister lhsMatrixCell objects;
     * falls back to the built-in matrix if OR is unavailable.
     *
     * @param string $gedrag  Gedrag code: A | B | C | D
     * @param string $gevolg  Mogelijke gevolgen column: 1 | 2 | 3 | 4
     *
     * @return array<string, mixed> {gedragRow, gevolgColumn, interventieStep, description}
     *
     * @throws RuntimeException When gedrag or gevolg is not a valid code.
     *
     * @spec openspec/changes/vth-module/tasks.md#task-8
     */
    public function lookup(string $gedrag, string $gevolg): array
    {
        $gedrag = strtoupper(trim($gedrag));
        $gevolg = trim($gevolg);

        if (array_key_exists($gedrag, self::FALLBACK_MATRIX) === false) {
            throw new RuntimeException('Ongeldig gedrag-code: '.$gedrag.'. Gebruik A, B, C of D.');
        }

        if (array_key_exists($gevolg, self::FALLBACK_MATRIX['A']) === false) {
            throw new RuntimeException('Ongeldig gevolg-kolom: '.$gevolg.'. Gebruik 1, 2, 3 of 4.');
        }

        // Try OpenRegister first.
        $cell = $this->lookupFromOpenRegister(gedrag: $gedrag, gevolg: $gevolg);
        if ($cell !== null) {
            return $cell;
        }

        // Fall back to in-memory matrix.
        $entry = self::FALLBACK_MATRIX[$gedrag][$gevolg];
        return [
            'gedragRow'       => $gedrag,
            'gevolgColumn'    => $gevolg,
            'interventieStep' => $entry['interventieStep'],
            'description'     => $entry['description'],
        ];
    }//end lookup()

    /**
     * Retrieve a matrix cell from OpenRegister.
     *
     * @param string $gedrag Gedrag row code
     * @param string $gevolg Gevolg column code
     *
     * @return array<string, mixed>|null Cell data or null when OR unavailable / not found
     */
    private function lookupFromOpenRegister(string $gedrag, string $gevolg): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('lhs_matrix_cell_schema');

        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $results = $objectService->findObjects(
                $register,
                $schema,
                ['gedragRow' => $gedrag, 'gevolgColumn' => $gevolg],
                [],
                1,
            );

            if (is_array($results) === true && count($results) > 0) {
                $cell = is_array($results[0]) ? $results[0] : [];
                return $cell !== [] ? $cell : null;
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: LHS OR lookup failed, falling back to built-in matrix: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
        }

        return null;
    }//end lookupFromOpenRegister()
}//end class
