<?php

/**
 * Dossiq OpenRegister gateway for the case-sharing surface.
 *
 * The single place the sharing surface reaches into OpenRegister. Every
 * resolution is guarded twice — the app must be installed, and the resolved
 * service must actually expose the methods the caller will invoke — because
 * dossiq runs against OpenRegister builds that predate the shares and
 * federation leaves. A missing leaf resolves to null; it never throws.
 *
 * Split out of CaseSharingService so the "is the leaf there?" question is
 * answered in one place for all three sharing modes (token links, partner
 * hand-off, OCM federation), and so each mode's service can state its own
 * fail-open/fail-closed policy over a uniform null.
 *
 * `toArray()` lives here for the same reason: OpenRegister hands back either a
 * plain array or an ObjectEntity depending on the call path, and every caller
 * needs the same normalisation before reading fields.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the OpenRegister services the case-sharing surface depends on.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class OpenRegisterSharingGateway {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the ObjectService from the DI container.
	 *
	 * @return object|null The ObjectService, or null when OpenRegister is unavailable
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 */
	public function objectService(): ?object {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseSharingService: ObjectService unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end objectService()

	/**
	 * Resolve OpenRegister's AccessLinkService, which mints the links a case
	 * share is made of (openregister#3817).
	 *
	 * OpenRegister owns the anchor, the expiry, the password check, the
	 * revoke and the single 404 that covers unknown, revoked, paused and
	 * expired alike. Dossiq decides only which subject is published and what
	 * its holder may do.
	 *
	 * The method check is the version test: an OpenRegister that predates
	 * #3817 has no such class, and one that has an older shape is refused
	 * here rather than half-called later.
	 *
	 * @return object|null The OR AccessLinkService, or null when it is not there.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function accessLinkService(): ?object {
		return $this->resolve(
			className: 'OCA\OpenRegister\Service\Sharing\AccessLinkService',
			methods: ['mint', 'revoke', 'setDisabled', 'resolve']
		);
	}//end accessLinkService()

	/**
	 * Resolve OpenRegister's AccessLinkReader, which decides what a holder
	 * reads through a link.
	 *
	 * Dossiq uses it for one thing only: showing a handler what the outside
	 * sees before they send the link.
	 *
	 * @return object|null The OR AccessLinkReader, or null when it is not there.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function accessLinkReader(): ?object {
		return $this->resolve(
			className: 'OCA\OpenRegister\Service\Sharing\AccessLinkReader',
			methods: ['read']
		);
	}//end accessLinkReader()

	/**
	 * Resolve OpenRegister's NoteService, which holds the comments a link
	 * holder writes.
	 *
	 * An advisory body answering a consultation writes a note as the link,
	 * and this is how dossiq reads it back.
	 *
	 * @return object|null The OR NoteService, or null when it is not there.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	public function noteService(): ?object {
		return $this->resolve(
			className: 'OCA\OpenRegister\Service\NoteService',
			methods: ['getNotesForObject']
		);
	}//end noteService()

	/**
	 * Resolve one OpenRegister service, and refuse it unless it carries every
	 * method the caller will invoke.
	 *
	 * @param string $className The fully qualified class name.
	 * @param array<int, string> $methods The methods the caller will invoke.
	 *
	 * @return object|null The service, or null.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function resolve(string $className, array $methods): ?object {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		try {
			$service = $this->container->get($className);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseSharingService: OpenRegister service unavailable',
				['class' => $className, 'exception' => $e->getMessage()]
			);
			return null;
		}

		foreach ($methods as $method) {
			if (method_exists($service, $method) === false) {
				$this->logger->warning(
					'CaseSharingService: OpenRegister service is an older shape than this code calls',
					['class' => $className, 'missing' => $method]
				);
				return null;
			}
		}

		return $service;
	}//end resolve()

	/**
	 * Resolve OpenRegister's FederationShareService — the leaf that owns
	 * OCM token minting, transport and lifecycle status. Returns null (fail
	 * closed for federation callers) when OR or its federation classes are
	 * unavailable.
	 *
	 * @return object|null The OR FederationShareService, or null
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 */
	public function federationShareService(): ?object {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		try {
			$service = $this->container->get('OCA\OpenRegister\Service\FederationShareService');
			if (method_exists($service, 'createOutgoingShare') === false || method_exists($service, 'setStatus') === false) {
				return null;
			}

			return $service;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseSharingService: OR FederationShareService unavailable (federation leaf not present)',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end federationShareService()

	/**
	 * Normalize an OpenRegister return value (array or ObjectEntity) to an array.
	 *
	 * @param mixed $value The value returned by the ObjectService
	 *
	 * @return array<string, mixed> The value as a plain array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 */
	public function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			return (array)$value->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end class
