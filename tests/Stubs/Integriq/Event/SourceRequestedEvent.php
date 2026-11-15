<?php

/**
 * Integriq SourceRequestedEvent test stub.
 *
 * Mirrors integriq's find-or-create Source contract verbatim (constructor
 * parameter names AND order, and the result slot) so RetiredWebhookSteps can
 * be unit-tested without the integriq app installed. The real class ships in
 * integriq (`lib/Event/SourceRequestedEvent.php`, change
 * source-requested-event); this stub is loaded by tests/bootstrap.php only
 * when the real class is absent.
 *
 * @category Tests
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "find or create the Source for this base URL".
 *
 * Carries provenance (which app, which user, for what purpose) and a
 * synchronous result slot the in-process listener writes: `isHandled()`,
 * `getSourceId()`, `getSourceSlug()`, `wasCreated()`, or `getRefusal()` when
 * the request was refused.
 */
class SourceRequestedEvent extends Event {
	/**
	 * Whether an Integriq listener handled the request.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The uuid of the Source found or created.
	 *
	 * @var string|null
	 */
	private ?string $sourceId = null;

	/**
	 * The slug of the Source found or created.
	 *
	 * @var string|null
	 */
	private ?string $sourceSlug = null;

	/**
	 * Whether the Source was created by this request.
	 *
	 * @var bool
	 */
	private bool $created = false;

	/**
	 * Why the request was refused, when it was.
	 *
	 * @var string|null
	 */
	private ?string $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string      $sourceApp      The requesting app id (e.g. `dossiq`).
	 * @param string      $baseUrl        The base URL: scheme, host and optional port, no path.
	 * @param string      $purpose        What the Source is for, written into its description.
	 * @param int|null    $timeoutSeconds The request timeout a created Source gets, or null for the default.
	 * @param string|null $userId         The acting Nextcloud user, or null for a system request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $baseUrl,
		private readonly string $purpose,
		private readonly ?int $timeoutSeconds = null,
		private readonly ?string $userId = null,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The requesting app id.
	 *
	 * @return string The source app id.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The requested base URL.
	 *
	 * @return string The base URL.
	 */
	public function getBaseUrl(): string {
		return $this->baseUrl;
	}//end getBaseUrl()

	/**
	 * What the Source is for.
	 *
	 * @return string The purpose.
	 */
	public function getPurpose(): string {
		return $this->purpose;
	}//end getPurpose()

	/**
	 * The request timeout a created Source gets.
	 *
	 * @return int|null The timeout in seconds, or null for the default.
	 */
	public function getTimeoutSeconds(): ?int {
		return $this->timeoutSeconds;
	}//end getTimeoutSeconds()

	/**
	 * The acting Nextcloud user.
	 *
	 * @return string|null The user id, or null for a system request.
	 */
	public function getUserId(): ?string {
		return $this->userId;
	}//end getUserId()

	/**
	 * Record the Source that answers the request, and mark it handled.
	 *
	 * @param string $sourceId   The Source uuid.
	 * @param string $sourceSlug The Source slug.
	 * @param bool   $created    Whether this request created it.
	 *
	 * @return void
	 */
	public function setSource(string $sourceId, string $sourceSlug, bool $created): void {
		$this->sourceId = $sourceId;
		$this->sourceSlug = $sourceSlug;
		$this->created = $created;
		$this->refusal = null;
		$this->handled = true;
	}//end setSource()

	/**
	 * Record why the request was refused. The event stays unhandled.
	 *
	 * @param string $refusal The reason.
	 *
	 * @return void
	 */
	public function refuse(string $refusal): void {
		$this->refusal = $refusal;
		$this->handled = false;
	}//end refuse()

	/**
	 * Whether an Integriq listener answered the request with a Source.
	 *
	 * @return bool True when handled.
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The Source uuid, once handled.
	 *
	 * @return string|null The uuid.
	 */
	public function getSourceId(): ?string {
		return $this->sourceId;
	}//end getSourceId()

	/**
	 * The Source slug, once handled.
	 *
	 * @return string|null The slug.
	 */
	public function getSourceSlug(): ?string {
		return $this->sourceSlug;
	}//end getSourceSlug()

	/**
	 * Whether this request created the Source.
	 *
	 * @return bool True when created, false when an existing one was found.
	 */
	public function wasCreated(): bool {
		return $this->created;
	}//end wasCreated()

	/**
	 * Why the request was refused.
	 *
	 * @return string|null The reason, or null when it was not refused.
	 */
	public function getRefusal(): ?string {
		return $this->refusal;
	}//end getRefusal()
}//end class
