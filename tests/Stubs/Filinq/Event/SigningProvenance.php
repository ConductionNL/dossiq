<?php

/**
 * Test stub: a verbatim copy of filinq's lib/Event/SigningProvenance.php (filinq development
 * b26bc2b, read 2026-10-14), loaded by tests/bootstrap.php only when the real class is
 * absent, so dossiq's signing code can be tested and analysed without filinq. If filinq
 * changes the class, change this copy with it. Its spec links point into filinq's
 * repository and are left out here.
 *
 * Filinq SigningProvenance
 *
 * Immutable provenance envelope carried by the cross-app signing events: which
 * consumer app asked for the signature, which OpenRegister object it originated
 * from, and the consumer's own linking/correlation references. Grouped into a
 * single value object so the event constructors stay legible.
 *
 * @category  Event
 * @package   OCA\Filinq\Event
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.filinq.app
 *
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Filinq\Event;

/**
 * Provenance reference for a delegated signing request.
 *
 * @category Event
 * @package  OCA\Filinq\Event
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.filinq.app
 *
 */
final class SigningProvenance {
	/**
	 * Construct the provenance envelope.
	 *
	 * @param string $sourceApp Consumer app that requested the signature
	 * @param string|null $subjectRegister OpenRegister register of the originating object
	 * @param string|null $subjectSchema OpenRegister schema of the originating object
	 * @param string|null $subjectId OpenRegister id of the originating object
	 * @param string $externalReference Consumer's own reference
	 * @param string $correlationId Correlation id from the request event
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp = '',
		private readonly ?string $subjectRegister = null,
		private readonly ?string $subjectSchema = null,
		private readonly ?string $subjectId = null,
		private readonly string $externalReference = '',
		private readonly string $correlationId = '',
	) {

	}//end __construct()

	/**
	 * Get the consumer app that requested the signature.
	 *
	 * @return string The source app id.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * Get the OpenRegister register of the originating object.
	 *
	 * @return string|null The subject register, or null.
	 */
	public function getSubjectRegister(): ?string {
		return $this->subjectRegister;
	}//end getSubjectRegister()

	/**
	 * Get the OpenRegister schema of the originating object.
	 *
	 * @return string|null The subject schema, or null.
	 */
	public function getSubjectSchema(): ?string {
		return $this->subjectSchema;
	}//end getSubjectSchema()

	/**
	 * Get the OpenRegister id of the originating object.
	 *
	 * @return string|null The subject id, or null.
	 */
	public function getSubjectId(): ?string {
		return $this->subjectId;
	}//end getSubjectId()

	/**
	 * Get the consumer's own external reference.
	 *
	 * @return string The external reference.
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * Get the correlation id from the request event.
	 *
	 * @return string The correlation id.
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()
}//end class
