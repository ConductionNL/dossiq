<?php

/**
 * Procest OpenRegister Archival Adapter.
 *
 * Repoints beschikking archival onto the OpenRegister archival pipeline: the
 * beschikking is a durable OpenRegister object and its retention/destruction is
 * governed declaratively by the `x-openregister-archival` configuration on the
 * case schema (ADR-022 / migrate-archival-to-or). This adapter records the
 * archival marker and computes the Archiefwet vernietigingsdatum from the
 * beschikking's declared bewaartermijn, delegating duration handling to OR's
 * TmloService when available. It replaces the retired MockArchivalAdapter,
 * whose 15-year retention was hard-coded app-side.
 *
 * @category Service
 * @package  OCA\Procest\Service\Beschikking
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
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister-backed implementation of the archival adapter.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
class OpenRegisterArchivalAdapter implements ArchivalAdapterInterface {

	/**
	 * OpenRegister TMLO service FQN (resolved lazily; optional).
	 *
	 * @var string
	 */
	private const TMLO_SERVICE = 'OCA\OpenRegister\Service\TmloService';

	/**
	 * Fallback bewaartermijn (ISO-8601) when the metadata declares none.
	 *
	 * @var string
	 */
	private const DEFAULT_BEWAARTERMIJN = 'P15Y';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (OR TmloService resolved lazily).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $beschikkingId The beschikking UUID.
	 * @param string $bestandId The signed PDF/A-3 file id.
	 * @param array<string, mixed> $tmloMetadata The TMLO-1.2/MDTO metadata block.
	 *
	 * @return array{archiefId: string, vernietigingsdatum: string}
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	public function ingest(string $beschikkingId, string $bestandId, array $tmloMetadata): array {
		$bewaartermijn = (string)($tmloMetadata['bewaartermijn'] ?? self::DEFAULT_BEWAARTERMIJN);
		if ($this->isValidDuration(duration: $bewaartermijn) === false) {
			$bewaartermijn = self::DEFAULT_BEWAARTERMIJN;
		}

		$vernietigingsdatum = $this->computeVernietigingsdatum(
			metadata: $tmloMetadata,
			bewaartermijn: $bewaartermijn
		);

		return [
			'archiefId' => 'openregister-' . substr(hash('sha256', $beschikkingId . $bestandId), 0, 12),
			'vernietigingsdatum' => $vernietigingsdatum,
		];
	}//end ingest()

	/**
	 * Compute the Archiefwet vernietigingsdatum: creatie/bekendmaking date plus
	 * the declared bewaartermijn (retention runs from creation, not archival).
	 *
	 * @param array<string, mixed> $metadata The TMLO metadata block.
	 * @param string $bewaartermijn Validated ISO-8601 duration.
	 *
	 * @return string The vernietigingsdatum (Y-m-d), or '' when uncomputable.
	 */
	private function computeVernietigingsdatum(array $metadata, string $bewaartermijn): string {
		$creatie = (string)($metadata['creatieDatum'] ?? ($metadata['bekendmakingDatum'] ?? ''));

		try {
			$base = new DateTimeImmutable();
			if ($creatie !== '') {
				$base = new DateTimeImmutable($creatie);
			}

			return $base->add(new DateInterval($bewaartermijn))->format('Y-m-d');
		} catch (Exception $e) {
			$this->logger->warning(
				'OpenRegisterArchivalAdapter: could not compute vernietigingsdatum',
				['error' => $e->getMessage()]
			);
			return '';
		}
	}//end computeVernietigingsdatum()

	/**
	 * Validate an ISO-8601 duration, delegating to OR's TmloService when present.
	 *
	 * @param string $duration Candidate ISO-8601 duration.
	 *
	 * @return bool True when the duration is parseable.
	 */
	private function isValidDuration(string $duration): bool {
		if ($duration === '') {
			return false;
		}

		$tmloService = $this->resolveTmloService();
		if ($tmloService !== null) {
			try {
				return $tmloService->calculateArchiefactiedatum($duration) !== null;
			} catch (\Throwable $e) {
				// Fall through to local parse.
			}
		}

		try {
			new DateInterval($duration);
			return true;
		} catch (Exception $e) {
			return false;
		}
	}//end isValidDuration()

	/**
	 * Resolve OR's TmloService, or null when the OpenRegister app is absent.
	 *
	 * @return object|null
	 */
	private function resolveTmloService(): ?object {
		if (class_exists(self::TMLO_SERVICE) === false) {
			return null;
		}

		try {
			$service = $this->container->get(self::TMLO_SERVICE);
			if (is_object($service) === true) {
				return $service;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveTmloService()
}//end class
