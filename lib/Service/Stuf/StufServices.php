<?php

/**
 * Procest StufServices collaborator bundle.
 *
 * The StUF SOAP surface needs eight collaborators to answer one request:
 * a field mapper, a message builder, an outbound adapter, register access,
 * an audit-log handler, a parser, the vault adapter and the circuit breaker.
 * Injecting them one by one pushed the controller constructor to twelve
 * parameters; this immutable bundle carries them as a single dependency and
 * is itself autowired by the Nextcloud container.
 *
 * @category Service
 * @package  OCA\Procest\Service\Stuf
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
 * @spec openspec/specs/stuf-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Stuf;

use OCA\Procest\Service\StufFieldMappingService;
use OCA\Procest\Service\StufMessageBuilder;

/**
 * Immutable bundle of the collaborators the StUF surface needs.
 *
 * @spec openspec/specs/stuf-integration/spec.md
 */
class StufServices {
	/**
	 * Constructor.
	 *
	 * @param StufFieldMappingService $mappingService The field mapping service.
	 * @param StufMessageBuilder $messageBuilder The message builder service.
	 * @param StufAdapterService $adapter The outbound adapter.
	 * @param StufRegisterAccess $register The register access helper.
	 * @param StufMessageHandler $messageHandler The audit log handler.
	 * @param StufMessageParser $parser The message parser.
	 * @param StufVaultService $vault The vault adapter.
	 * @param CircuitBreakerService $circuitBreaker The circuit breaker.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly StufFieldMappingService $mappingService,
		public readonly StufMessageBuilder $messageBuilder,
		public readonly StufAdapterService $adapter,
		public readonly StufRegisterAccess $register,
		public readonly StufMessageHandler $messageHandler,
		public readonly StufMessageParser $parser,
		public readonly StufVaultService $vault,
		public readonly CircuitBreakerService $circuitBreaker,
	) {
	}//end __construct()
}//end class
