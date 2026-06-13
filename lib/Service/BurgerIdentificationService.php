<?php

/**
 * Procest Burger Identification Service.
 *
 * Resolves the calling burger for a KCC contact, either from a DigiD assertion
 * (via openconnector) or from progressive identificatievragen with a weighted
 * confidence score. A burger is a Nextcloud contact entity (resolved through
 * OCP\Contacts\IManager) — this service deliberately does NOT persist a bespoke
 * person/customer schema (ADR guardrail); it returns an opaque burger reference
 * derived from the matched contact.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\Contacts\IManager as IContactsManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolve and score burger identification for KCC contacts.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T05
 */
class BurgerIdentificationService
{
    /**
     * Weighting per identificatievraag dimension (must sum to 1.0).
     *
     * @var array<string, float>
     */
    private const WEIGHTS = [
        'naam'          => 0.30,
        'geboortedatum' => 0.30,
        'adres'         => 0.20,
        'bsn'           => 0.15,
        'out_of_wallet' => 0.05,
    ];

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService The settings service.
     * @param ContainerInterface $container       The DI container.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute a weighted identificatievragen match score.
     *
     * Each input is a boolean-ish flag indicating whether the citizen's answer
     * matched the record. The score is the sum of the weights of the matched
     * dimensions, clamped to [0, 1].
     *
     * @param array<string, bool> $matched Map of dimension => matched flag.
     *
     * @return float The identification score (0.0 - 1.0).
     *
     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T05
     */
    public function calculateScore(array $matched): float
    {
        $score = 0.0;
        foreach (self::WEIGHTS as $dimension => $weight) {
            if (($matched[$dimension] ?? false) === true) {
                $score += $weight;
            }
        }

        return round(max(0.0, min(1.0, $score)), 2);
    }//end calculateScore()

    /**
     * Run the identificatievragen flow and decide whether the burger is linked.
     *
     * @param array<string, bool> $matched   The per-dimension match flags.
     * @param string              $burgerRef The candidate burger reference.
     *
     * @return array{score: float, identified: bool, burgerId: ?string, method: string}
     *
     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T05
     */
    public function startIdentificatievragen(array $matched, string $burgerRef): array
    {
        $score      = $this->calculateScore(matched: $matched);
        $threshold  = (float) $this->settingsService->getKccConfigValue('identification_score_threshold');
        $identified = ($score >= $threshold && $burgerRef !== '');

        $burgerId = null;
        if ($identified === true) {
            $burgerId = $burgerRef;
        }

        return [
            'score'      => $score,
            'identified' => $identified,
            'burgerId'   => $burgerId,
            'method'     => 'identificatievragen',
        ];
    }//end startIdentificatievragen()

    /**
     * Resolve a burger reference from a DigiD assertion's BSN.
     *
     * The BSN is never returned to the client and never logged in cleartext;
     * the returned reference is a one-way pseudonymous identifier so downstream
     * contactmoment records can correlate contacts without storing the BSN.
     *
     * @param string $bsn The BSN extracted from the validated DigiD assertion.
     *
     * @return array{burgerId: string, method: string}
     *
     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T05
     */
    public function resolveFromDigiD(string $bsn): array
    {
        $bsn = trim($bsn);
        if ($bsn === '') {
            return ['burgerId' => '', 'method' => 'niet_geidentificeerd'];
        }

        $this->logger->info(
            'Procest: DigiD identification processed (BSN masked)',
            [
                'app' => Application::APP_ID,
                'bsn' => $this->maskBsn(bsn: $bsn),
            ],
        );

        return [
            'burgerId' => $this->pseudonymize(bsn: $bsn),
            'method'   => 'digid',
        ];
    }//end resolveFromDigiD()

    /**
     * Look up a burger reference by phone number or email via NC contacts.
     *
     * @param string $identifier A phone number or email address.
     *
     * @return string The burger reference, or empty string when not found.
     *
     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T05
     */
    public function lookupByIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return '';
        }

        $manager = $this->resolveContactsManager();
        if ($manager === null) {
            return '';
        }

        $field = 'TEL';
        if (str_contains($identifier, '@') === true) {
            $field = 'EMAIL';
        }

        try {
            $matches = $manager->search($identifier, [$field]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest: contacts lookup failed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return '';
        }

        foreach ((array) $matches as $match) {
            $uid = (string) ($match['UID'] ?? '');
            if ($uid !== '') {
                return 'contact:'.$uid;
            }
        }

        return '';
    }//end lookupByIdentifier()

    /**
     * Resolve the optional Nextcloud contacts manager.
     *
     * @return IContactsManager|null The manager, or null when unavailable.
     */
    private function resolveContactsManager(): ?IContactsManager
    {
        try {
            $manager = $this->container->get(IContactsManager::class);
        } catch (\Throwable $e) {
            return null;
        }

        if ($manager instanceof IContactsManager) {
            return $manager;
        }

        return null;
    }//end resolveContactsManager()

    /**
     * Produce a stable pseudonymous reference for a BSN (never the raw BSN).
     *
     * @param string $bsn The BSN.
     *
     * @return string A pseudonymous burger reference.
     */
    private function pseudonymize(string $bsn): string
    {
        return 'burger:'.substr(hash('sha256', 'procest-kcc:'.$bsn), 0, 24);
    }//end pseudonymize()

    /**
     * Mask a BSN for logging, keeping only the last two digits.
     *
     * @param string $bsn The BSN.
     *
     * @return string The masked BSN.
     */
    private function maskBsn(string $bsn): string
    {
        $len = strlen($bsn);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', ($len - 2)).substr($bsn, -2);
    }//end maskBsn()
}//end class
