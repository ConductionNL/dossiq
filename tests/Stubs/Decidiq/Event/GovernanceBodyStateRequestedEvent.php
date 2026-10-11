<?php

/**
 * Decidiq GovernanceBodyStateRequestedEvent test stub.
 *
 * Mirrors decidiq's READ contract for governance bodies (decidiq#1694) so
 * dossiq's GovernanceBodyReader can be unit-tested without decidiq installed.
 * The real class ships in decidiq (`OCA\Decidiq\Event\GovernanceBodyStateRequestedEvent`);
 * tests/bootstrap.php loads this stub only when the real class is absent.
 *
 * IT MIRRORS THE REAL API, not the caller's assumption: two result slots kept
 * apart (handled, and a body or null), positional constructor (sourceApp,
 * externalReference, governanceBodyId).
 *
 * ONE NAMESPACE ONLY: the read half was added after the OCA\Decidesk rename.
 *
 * @category Tests
 * @package  OCA\Decidiq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://decidiq.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Asks decidiq for one governance body a consumer raised, with its roster.
 */
class GovernanceBodyStateRequestedEvent extends Event {

	/**
	 * Whether decidiq answered at all.
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * The body, when decidiq holds one for this consumer.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $governanceBody = null;

	/**
	 * @param string $sourceApp         The asking app.
	 * @param string $externalReference The asking app's own key for the body.
	 * @param string $governanceBodyId  decidiq's id, when the asking app holds it.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $externalReference = '',
		private readonly string $governanceBodyId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * @return string The asking app.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * @return string The asking app's own key.
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * @return string decidiq's id, or ''.
	 */
	public function getGovernanceBodyId(): string {
		return $this->governanceBodyId;
	}//end getGovernanceBodyId()

	/**
	 * @return bool Whether decidiq answered.
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * @param bool $handled Whether decidiq answered.
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()

	/**
	 * @return bool Whether decidiq holds the body.
	 */
	public function isFound(): bool {
		return $this->governanceBody !== null;
	}//end isFound()

	/**
	 * @return array<string, mixed>|null The body.
	 */
	public function getGovernanceBody(): ?array {
		return $this->governanceBody;
	}//end getGovernanceBody()

	/**
	 * @param array<string, mixed> $governanceBody The body.
	 *
	 * @return void
	 */
	public function setGovernanceBody(array $governanceBody): void {
		$this->governanceBody = $governanceBody;
	}//end setGovernanceBody()
}//end class
