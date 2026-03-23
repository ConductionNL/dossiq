---
status: draft
---

# Document en Zaakdossier

**Owned by**: Procest (case dossier management)

## Purpose
Provide case dossier (zaakdossier) management within Procest, integrating document management with case objects to create ZGW Documenten API (DRC) compliant dossiers that leverage Nextcloud Files as the underlying storage layer. This is a core Procest capability: every zaak needs a structured document dossier. Documents stored in Nextcloud Files MUST be linkable to case objects with ZGW-compliant metadata (titel, vertrouwelijkheidaanduiding, auteur, status, informatieobjecttype), support a full document lifecycle (concept -> definitief -> gearchiveerd), and be organized into structured dossier folder hierarchies. The system MUST provide file upload with security validation, document versioning via Nextcloud's native versioning, full-text search through `TextExtractionService`, document sharing and public access via `FileSharingHandler`, and bulk operations including ZIP archive export via `FilePublishingHandler`. OpenRegister provides the file management infrastructure; Procest owns the dossier workflow and ZGW DRC compliance layer.

**Tender demand**: 80% of analyzed government tenders require document management in case dossiers. 65% specifically reference ZGW DRC compliance (informatieobjecten, vertrouwelijkheidaanduiding, document status lifecycle).

## Requirements

### Requirement: Register objects MUST support linked documents with ZGW informatieobject metadata
Objects MUST be able to reference one or more documents stored in Nextcloud Files. Each document link MUST carry ZGW DRC-compliant metadata fields stored as properties on an `informatieobject` schema within the register. The link between a zaak object and an informatieobject MUST follow the ZGW `zaakinformatieobject` pattern (a separate join entity with metadata about the relationship).

#### Scenario: Link a document to an object with ZGW metadata
- **GIVEN** an object `vergunning-1` in schema `vergunningen` within a register
- **WHEN** the user uploads a document `aanvraagformulier.pdf` to the object
- **THEN** the document MUST be stored in Nextcloud Files via `CreateFileHandler.addFile()` in the object's folder (resolved by `FolderManagementHandler.getObjectFolder()`)
- **AND** an `informatieobject` register object MUST be created with the following ZGW DRC-compliant metadata:
  - `titel`: `aanvraagformulier.pdf`
  - `vertrouwelijkheidaanduiding`: one of `openbaar`, `beperkt_openbaar`, `intern`, `zaakvertrouwelijk`, `vertrouwelijk`, `confidentieel`, `geheim`, `zeer_geheim`
  - `auteur`: display name of the uploading user
  - `status`: `concept` (default for new uploads)
  - `informatieobjecttype`: reference to the document type from the catalog
  - `creatiedatum`: current date (ISO 8601)
  - `bronorganisatie`: RSIN of the organization
  - `taal`: `nld` (ISO 639-2/B language code, default Dutch)
  - `bestandsnaam`: `aanvraagformulier.pdf`
  - `bestandsomvang`: file size in bytes
  - `formaat`: MIME type (e.g., `application/pdf`)
  - `link`: empty (reserved for external document references)
  - `beschrijving`: optional description
- **AND** a `zaakinformatieobject` join object MUST be created linking `vergunning-1` to the informatieobject with:
  - `zaak`: reference to `vergunning-1`
  - `informatieobject`: reference to the created informatieobject
  - `aardRelatieWeergave`: one of `Hoort bij, omgekeerd`, `Legt vast, omgekeerd`
  - `registratiedatum`: current timestamp

#### Scenario: Link multiple documents to a single object
- **GIVEN** object `vergunning-1` already has `aanvraagformulier.pdf` linked
- **WHEN** the user uploads `situatietekening.pdf` and `foto-locatie.jpg`
- **THEN** separate `informatieobject` register objects MUST be created for each file
- **AND** separate `zaakinformatieobject` join objects MUST link each to `vergunning-1`
- **AND** all three documents MUST appear in the object's dossier view
- **AND** the dossier MUST display titel, informatieobjecttype, status, creatiedatum, bestandsomvang, and vertrouwelijkheidaanduiding for each

#### Scenario: Link an existing informatieobject to a second zaak
- **GIVEN** informatieobject `advies-brandweer.pdf` is already linked to `vergunning-1`
- **WHEN** the user links the same document to `vergunning-2`
- **THEN** a new `zaakinformatieobject` join object MUST be created for `vergunning-2`
- **AND** the informatieobject itself MUST NOT be duplicated
- **AND** both zaak dossier views MUST show the document

#### Scenario: Upload document with automatic object tagging
- **GIVEN** the `CreateFileHandler.addFile()` method automatically attaches an `object:{uuid}` system tag via `TaggingHandler`
- **WHEN** a document is uploaded to `vergunning-1`
- **THEN** the Nextcloud file MUST receive a system tag `object:{vergunning-1-uuid}`
- **AND** additional tags for informatieobjecttype (e.g., `doctype:aanvraag`) MUST be attached
- **AND** files MUST be discoverable by tag in both OpenRegister and Nextcloud Files app

### Requirement: Documents MUST follow the ZGW informatieobject status lifecycle
Each informatieobject MUST support a status lifecycle conforming to the ZGW DRC standard. Status transitions MUST be validated and enforced by the system. Once a document reaches `definitief` status, its content MUST become immutable (read-only in Nextcloud Files).

#### Scenario: New document defaults to concept status
- **GIVEN** a user uploads a new document to an object
- **WHEN** the informatieobject is created
- **THEN** the `status` field MUST default to `concept`
- **AND** the document content MUST be editable (new versions can be uploaded)

#### Scenario: Transition from concept to definitief
- **GIVEN** an informatieobject with `status` = `concept`
- **WHEN** a user with sufficient permissions changes the status to `definitief`
- **THEN** the status MUST be updated to `definitief`
- **AND** the document content MUST become read-only (subsequent uploads to the same filename MUST be rejected)
- **AND** the `vergrendeldOp` timestamp MUST be set to the current time

#### Scenario: Reject invalid status transitions
- **GIVEN** an informatieobject with `status` = `definitief`
- **WHEN** a user attempts to change the status back to `concept`
- **THEN** the system MUST reject the transition with HTTP 400 Bad Request
- **AND** the response MUST indicate that `definitief` documents cannot revert to `concept`
- **AND** only the transition to `gearchiveerd` MUST be permitted from `definitief`

#### Scenario: Transition from definitief to gearchiveerd
- **GIVEN** an informatieobject with `status` = `definitief`
- **AND** the associated zaak has `archiefstatus` = `gearchiveerd` (see `archivering-vernietiging` spec)
- **WHEN** the archival process triggers status transition
- **THEN** the informatieobject status MUST change to `gearchiveerd`
- **AND** the document MUST be included in SIP package exports (see `archivering-vernietiging` spec)

#### Scenario: Prevent deletion of definitief documents
- **GIVEN** an informatieobject with `status` = `definitief`
- **WHEN** a user attempts to delete the document via `DeleteFileHandler`
- **THEN** the deletion MUST be rejected with HTTP 409 Conflict
- **AND** the informatieobject record MUST remain intact
- **AND** only documents with `status` = `concept` MAY be deleted

### Requirement: Document metadata MUST include vertrouwelijkheidaanduiding (confidentiality classification)
Each informatieobject MUST carry a `vertrouwelijkheidaanduiding` (confidentiality classification) that controls access and visibility. The classification MUST conform to the ZGW DRC enumeration and integrate with Nextcloud's sharing permissions.

#### Scenario: Set vertrouwelijkheidaanduiding on upload
- **GIVEN** the upload dialog presents the vertrouwelijkheidaanduiding options
- **WHEN** a user uploads a document with `vertrouwelijkheidaanduiding` = `zaakvertrouwelijk`
- **THEN** the informatieobject MUST store `zaakvertrouwelijk` as the classification
- **AND** the document MUST only be visible to users with access to the parent zaak
- **AND** the document MUST NOT be included in public dossier exports

#### Scenario: Enforce vertrouwelijkheidaanduiding hierarchy
- **GIVEN** the ZGW confidentiality levels ordered from least to most restrictive: `openbaar`, `beperkt_openbaar`, `intern`, `zaakvertrouwelijk`, `vertrouwelijk`, `confidentieel`, `geheim`, `zeer_geheim`
- **WHEN** a user with maximum clearance level `vertrouwelijk` requests a document with `geheim` classification
- **THEN** the system MUST deny access with HTTP 403 Forbidden
- **AND** the document MUST NOT appear in search results for that user

#### Scenario: Default vertrouwelijkheidaanduiding from informatieobjecttype
- **GIVEN** an informatieobjecttype `intern-advies` with default vertrouwelijkheidaanduiding `intern`
- **WHEN** a user uploads a document of this type without specifying a classification
- **THEN** the vertrouwelijkheidaanduiding MUST default to `intern`
- **AND** the user MAY override the default to a more restrictive level but NOT to a less restrictive one

### Requirement: The system MUST provide a structured zaakdossier view
Each zaak object MUST have a dossier tab showing all linked informatieobjecten organized by informatieobjecttype. The dossier view MUST render documents grouped in a folder-like structure corresponding to document types.

#### Scenario: Display dossier for a vergunning with document types
- **GIVEN** vergunning `vergunning-1` has 8 linked informatieobjecten across types: aanvraag (2), advies (3), besluit (1), correspondentie (2)
- **WHEN** the user opens the dossier tab
- **THEN** documents MUST be grouped by informatieobjecttype in collapsible sections
- **AND** each document MUST show: titel, status (with color indicator: concept=orange, definitief=green, gearchiveerd=grey), creatiedatum, auteur, bestandsomvang, vertrouwelijkheidaanduiding badge
- **AND** each document MUST be clickable to view in Nextcloud Files or download
- **AND** a document count badge MUST be shown on the dossier tab header (e.g., "Dossier (8)")

#### Scenario: Empty dossier with upload instructions
- **GIVEN** a new zaak object with no linked informatieobjecten
- **WHEN** the user opens the dossier tab
- **THEN** a helpful empty state MUST be shown with:
  - An upload button
  - Instructions explaining how to add documents to the dossier
  - A drag-and-drop zone indicator

#### Scenario: Filter documents within dossier
- **GIVEN** a dossier with 25 informatieobjecten
- **WHEN** the user applies filters for `status` = `definitief` and `vertrouwelijkheidaanduiding` = `openbaar`
- **THEN** only documents matching both criteria MUST be shown
- **AND** the filter state MUST be reflected in the URL for sharing/bookmarking

#### Scenario: Sort documents within dossier
- **GIVEN** a dossier with multiple documents
- **WHEN** the user clicks the "creatiedatum" column header
- **THEN** documents MUST be sorted by creation date (newest first by default, toggleable to oldest first)
- **AND** sorting MUST be available on all columns: titel, status, creatiedatum, auteur, bestandsomvang

### Requirement: Documents MUST support versioning via Nextcloud Files
Document versions MUST be tracked via Nextcloud's native file versioning system. The dossier view MUST expose version history for each document, and version creation MUST be restricted based on informatieobject status.

#### Scenario: Upload new version of a concept document
- **GIVEN** informatieobject `besluit.pdf` with `status` = `concept` and version 1 linked to `vergunning-1`
- **WHEN** the user uploads an updated `besluit.pdf` to the same object
- **THEN** Nextcloud Files MUST create a new version automatically (via `CreateFileHandler.saveFile()` upsert)
- **AND** the dossier MUST show `besluit.pdf (v2)` with access to version history
- **AND** version 1 MUST remain accessible via the Nextcloud versions API (`/dav/versions/{userId}/versions/{fileId}`)

#### Scenario: View document version history
- **GIVEN** `besluit.pdf` has 3 versions
- **WHEN** the user clicks "Versiegeschiedenis" on the document
- **THEN** a side panel MUST show all versions with: version number, timestamp, uploading user
- **AND** each version MUST be downloadable
- **AND** the panel MUST indicate which version is current

#### Scenario: Reject version upload for definitief document
- **GIVEN** informatieobject `besluit.pdf` with `status` = `definitief`
- **WHEN** the user attempts to upload a new version
- **THEN** the upload MUST be rejected with a clear error message: "Definitieve documenten kunnen niet worden gewijzigd"
- **AND** the existing file content MUST remain unchanged

#### Scenario: Restore a previous version of a concept document
- **GIVEN** `aanvraag.pdf` with `status` = `concept` has 3 versions
- **WHEN** the user selects "Herstellen" on version 1
- **THEN** version 1 content MUST become the current version (creating version 4)
- **AND** the informatieobject metadata MUST be updated with the new version's timestamp

### Requirement: File type validation and security scanning MUST be enforced on upload
All uploaded documents MUST pass security validation via `FileValidationHandler`. The system MUST block executable files (by extension and magic byte detection), validate MIME types against an allowlist for government document types, and enforce configurable file size limits per register.

#### Scenario: Block executable file upload
- **GIVEN** a user attempts to upload `malware.exe` to a zaak dossier
- **WHEN** `FileValidationHandler.blockExecutableFile()` checks the file
- **THEN** the upload MUST be rejected before the file is written to disk
- **AND** the rejection MUST check both the file extension against the dangerous extensions list (exe, bat, cmd, php, sh, py, etc.)
- **AND** the rejection MUST check magic bytes to detect renamed executables (MZ for PE/EXE, \x7FELF for Linux, <?php for PHP scripts)
- **AND** the error message MUST indicate: "File 'malware.exe' is an executable file (.exe). Executable files are blocked for security reasons."

#### Scenario: Block renamed executable by magic bytes
- **GIVEN** a user renames `trojan.exe` to `document.pdf` and attempts upload
- **WHEN** `FileValidationHandler.detectExecutableMagicBytes()` inspects the content
- **THEN** the upload MUST be rejected because the content starts with `MZ` (Windows PE header)
- **AND** the error MUST indicate: "File 'document.pdf' contains executable code (Windows executable (PE/EXE)). Executable files are blocked for security."

#### Scenario: Accept valid government document types
- **GIVEN** a user uploads a file with extension `.pdf`, `.docx`, `.xlsx`, `.odt`, `.jpg`, `.png`, or `.msg`
- **WHEN** the file passes extension and magic byte validation
- **THEN** the upload MUST be accepted
- **AND** the informatieobject `formaat` field MUST be set to the detected MIME type

#### Scenario: Enforce file size limits per register
- **GIVEN** register `vergunningen-register` has a configured maximum file size of 50 MB
- **WHEN** a user attempts to upload a 75 MB file
- **THEN** the upload MUST be rejected with HTTP 413 Payload Too Large
- **AND** the error message MUST indicate the maximum allowed file size

#### Scenario: Validate file integrity with hash
- **GIVEN** a user uploads `aanvraag.pdf`
- **WHEN** the file is stored via `CreateFileHandler`
- **THEN** the system MUST compute a SHA-256 hash of the file content
- **AND** the hash MUST be stored in the informatieobject's `integriteit.algoritme` (`sha256`) and `integriteit.waarde` fields
- **AND** subsequent downloads MUST allow hash verification

### Requirement: The system MUST support full-text search within dossier documents
Document content MUST be extractable and searchable via `TextExtractionService`. Full-text extraction MUST be performed asynchronously upon upload and stored for search indexing.

#### Scenario: Extract text from uploaded PDF
- **GIVEN** a user uploads `aanvraagformulier.pdf` containing text content
- **WHEN** the upload completes
- **THEN** `TextExtractionService` MUST asynchronously extract text content (via `FileTextExtractionJob` or `CronFileTextExtractionJob`)
- **AND** the extracted text MUST be stored for search indexing
- **AND** the document MUST be searchable by content via `FileSearchController`

#### Scenario: Search documents across a dossier by content
- **GIVEN** a dossier with 25 documents, 3 of which contain the phrase "brandveiligheid"
- **WHEN** the user searches for "brandveiligheid" in the dossier search bar
- **THEN** only the 3 matching documents MUST be returned
- **AND** search results MUST show the matching text snippet with context (highlighting)

#### Scenario: Search documents by metadata fields
- **GIVEN** a dossier with documents of various types and statuses
- **WHEN** the user searches for `auteur:jan AND informatieobjecttype:advies`
- **THEN** only documents authored by "jan" with type "advies" MUST be returned
- **AND** metadata search MUST be combinable with full-text content search

#### Scenario: Extract text from Word documents
- **GIVEN** a user uploads `rapport.docx`
- **WHEN** text extraction runs via `TextExtractionService` (using PhpWord IOFactory)
- **THEN** text content from all sections, headers, footers, and tables MUST be extracted
- **AND** the document MUST become full-text searchable

### Requirement: The zaakdossier MUST be represented as a Nextcloud folder structure
Each zaak's dossier MUST be stored as a structured folder hierarchy in Nextcloud Files, managed by `FolderManagementHandler`. The folder structure MUST be: `Open Registers/{Register Title} Register/{objectUuid}/`. Within the object folder, documents MAY be organized into informatieobjecttype sub-folders.

#### Scenario: Automatic folder creation on first document upload
- **GIVEN** a zaak object `vergunning-1` with UUID `abc-123` in register `Vergunningen`
- **WHEN** the first document is uploaded to this zaak
- **THEN** `FolderManagementHandler.createObjectFolderById()` MUST create the folder structure: `Open Registers/Vergunningen Register/abc-123/`
- **AND** the folder ID MUST be stored on the `ObjectEntity.folder` property
- **AND** the folder MUST be shared with the current user via `FileSharingHandler.shareFolderWithUser()`

#### Scenario: Browse dossier files in Nextcloud Files app
- **GIVEN** zaak `vergunning-1` has 5 documents in its dossier
- **WHEN** the user navigates to `Open Registers/Vergunningen Register/abc-123/` in Nextcloud Files
- **THEN** all 5 documents MUST be visible
- **AND** the user MUST be able to preview, download, and share files using standard Nextcloud Files operations
- **AND** changes made in Nextcloud Files (rename, delete of concept documents) MUST be reflected in the dossier view

#### Scenario: Informatieobjecttype sub-folder organization (optional)
- **GIVEN** the register administrator has enabled sub-folder organization by informatieobjecttype
- **WHEN** a document of type `advies` is uploaded
- **THEN** the file MUST be stored in `Open Registers/Vergunningen Register/abc-123/advies/filename.pdf`
- **AND** the dossier view MUST still show all documents regardless of sub-folder structure

### Requirement: Document-object linking MUST support bidirectional navigation
The link between documents and objects MUST be navigable in both directions: from an object to its documents (dossier view) and from a document to its linked objects. This supports the ZGW `objectinformatieobject` pattern.

#### Scenario: Navigate from object to documents
- **GIVEN** zaak `vergunning-1` has 3 linked informatieobjecten
- **WHEN** the user opens the dossier tab on `vergunning-1`
- **THEN** all 3 informatieobjecten MUST be displayed with their metadata

#### Scenario: Navigate from document to linked objects
- **GIVEN** informatieobject `advies-brandweer.pdf` is linked to both `vergunning-1` and `vergunning-2`
- **WHEN** the user views the metadata panel of `advies-brandweer.pdf`
- **THEN** the panel MUST show a "Gekoppelde zaken" section listing both `vergunning-1` and `vergunning-2`
- **AND** each zaak link MUST be clickable to navigate to the zaak detail view

#### Scenario: Unlink a document from a zaak
- **GIVEN** informatieobject `bijlage.pdf` is linked to `vergunning-1` via a `zaakinformatieobject`
- **WHEN** the user removes the link (not the document itself)
- **THEN** the `zaakinformatieobject` join object MUST be deleted
- **AND** the informatieobject itself MUST remain in the register (it may be linked to other objects)
- **AND** the file MUST remain in Nextcloud Files

### Requirement: Bulk document operations MUST be supported
Users MUST be able to perform operations on multiple dossier documents simultaneously, including bulk download as ZIP archive, bulk status transition, and bulk metadata update.

#### Scenario: Download complete dossier as ZIP
- **GIVEN** a dossier with 8 documents
- **WHEN** the user clicks "Download dossier"
- **THEN** `FilePublishingHandler.createObjectFilesZip()` MUST generate a ZIP archive containing all 8 documents
- **AND** the ZIP MUST preserve the informatieobjecttype folder structure
- **AND** the ZIP MUST include a `manifest.csv` with columns: bestandsnaam, titel, informatieobjecttype, status, vertrouwelijkheidaanduiding, creatiedatum, auteur
- **AND** documents with `vertrouwelijkheidaanduiding` higher than the user's clearance MUST be excluded from the ZIP

#### Scenario: Download selected documents as ZIP
- **GIVEN** the user selects 3 out of 8 documents in the dossier view
- **WHEN** the user clicks "Download selectie"
- **THEN** a ZIP archive MUST be generated containing only the 3 selected documents
- **AND** the manifest MUST list only the selected documents

#### Scenario: Bulk transition concept documents to definitief
- **GIVEN** the user selects 5 documents with `status` = `concept`
- **WHEN** the user clicks "Markeer als definitief"
- **THEN** all 5 documents MUST transition to `definitief` status
- **AND** any documents that fail validation MUST be reported individually without blocking the others
- **AND** the dossier view MUST refresh to show updated statuses

#### Scenario: Bulk update vertrouwelijkheidaanduiding
- **GIVEN** the user selects 3 documents with `vertrouwelijkheidaanduiding` = `openbaar`
- **WHEN** the user changes the classification to `intern` for all selected
- **THEN** all 3 informatieobjecten MUST be updated with the new classification
- **AND** sharing permissions MUST be re-evaluated for each document

### Requirement: Document sharing and public access MUST be controllable per document
Individual documents MUST support publishing (public share links) and sharing with specific users, managed through `FileSharingHandler` and `FilePublishingHandler`. Public access MUST respect the `vertrouwelijkheidaanduiding`.

#### Scenario: Publish a document with openbaar classification
- **GIVEN** informatieobject `besluit.pdf` with `vertrouwelijkheidaanduiding` = `openbaar` and `status` = `definitief`
- **WHEN** the user clicks "Publiceren" on the document
- **THEN** `FilePublishingHandler.publishFile()` MUST create a public share link via `FileMapper.publishFile()`
- **AND** the share link MUST be returned and displayable in the dossier view
- **AND** the link MUST allow anonymous download without authentication

#### Scenario: Block publishing of confidential documents
- **GIVEN** informatieobject `intern-advies.pdf` with `vertrouwelijkheidaanduiding` = `vertrouwelijk`
- **WHEN** the user attempts to publish the document
- **THEN** the system MUST reject the publish action
- **AND** the error MUST indicate: "Documenten met vertrouwelijkheidaanduiding 'vertrouwelijk' of hoger kunnen niet openbaar worden gemaakt"

#### Scenario: Share document with specific user
- **GIVEN** informatieobject `advies.pdf` in the dossier of `vergunning-1`
- **WHEN** the user shares the document with user `medewerker-2`
- **THEN** `FileSharingHandler.shareFileWithUser()` MUST create a user share
- **AND** `medewerker-2` MUST see the file in their Nextcloud Files shared folder
- **AND** existing shares MUST be checked to avoid duplicates

#### Scenario: Unpublish a previously published document
- **GIVEN** informatieobject `besluit.pdf` has an active public share link
- **WHEN** the user clicks "Depubliceren"
- **THEN** `FilePublishingHandler.unpublishFile()` MUST remove the public share via `FileMapper.depublishFile()`
- **AND** the previously shared link MUST return HTTP 404 for anonymous users

### Requirement: The system MUST support drag-and-drop upload to the dossier
Documents MUST be uploadable via drag-and-drop onto the dossier view. The drop action MUST trigger a metadata dialog for informatieobjecttype selection and vertrouwelijkheidaanduiding before upload.

#### Scenario: Drag-and-drop single file
- **GIVEN** the user is viewing the dossier tab of `vergunning-1`
- **WHEN** they drag a file from their desktop onto the dossier area
- **THEN** the system MUST display a visual drop zone indicator
- **AND** upon dropping, a metadata dialog MUST appear with required fields:
  - `informatieobjecttype`: dropdown populated from the register's informatieobjecttype catalog
  - `vertrouwelijkheidaanduiding`: dropdown with ZGW enumeration values (default from informatieobjecttype)
  - `titel`: pre-filled with the filename (editable)
  - `beschrijving`: optional text field
- **AND** after confirmation, the document MUST be uploaded via `CreateFileHandler.addFile()` and linked

#### Scenario: Drag-and-drop multiple files
- **GIVEN** the user drags 5 files onto the dossier area
- **WHEN** they drop the files
- **THEN** the metadata dialog MUST allow setting a shared informatieobjecttype and vertrouwelijkheidaanduiding for all files
- **AND** each file MUST be uploaded individually via `CreateFileHandler.addFile()`
- **AND** a progress indicator MUST show the upload status for each file
- **AND** any files that fail validation MUST be reported without blocking the others

#### Scenario: Cancel drag-and-drop
- **GIVEN** the user drags files onto the dossier area and the drop zone indicator appears
- **WHEN** they drag the files away from the dossier area
- **THEN** the drop zone indicator MUST disappear
- **AND** no upload MUST occur

### Requirement: Document type classification MUST be configurable via informatieobjecttypen
Each document in a dossier MUST have an informatieobjecttype (document type) for classification, organization, and ZGW compliance. Informatieobjecttypen MUST be configurable via a catalog schema.

#### Scenario: Configure informatieobjecttypen in the catalog
- **GIVEN** an admin configuring the informatieobjecttype catalog
- **WHEN** they create an informatieobjecttype with:
  - `omschrijving`: `Advies`
  - `vertrouwelijkheidaanduiding`: `intern` (default for this type)
  - `informatieobjectcategorie`: `advies`
  - `beginGeldigheid`: `2026-01-01`
  - `eindeGeldigheid`: null (currently valid)
- **THEN** the type MUST be available for selection when uploading documents
- **AND** the type MUST be available for filtering in the dossier view

#### Scenario: Require informatieobjecttype on upload
- **GIVEN** schema `vergunningen` has document type classification enabled
- **WHEN** a user uploads a document without selecting an informatieobjecttype
- **THEN** the upload dialog MUST block submission until a type is selected
- **AND** the required field MUST be visually indicated

#### Scenario: Configure document types per schema
- **GIVEN** schema `vergunningen` needs types: `aanvraag`, `advies`, `besluit`, `correspondentie`, `bijlage`
- **AND** schema `meldingen` needs types: `melding`, `foto`, `rapport`, `correspondentie`
- **WHEN** the admin configures informatieobjecttypen per schema
- **THEN** the upload dialog MUST only show relevant types for the current schema
- **AND** shared types like `correspondentie` MUST be reusable across schemas

### Requirement: Thumbnail generation and document preview MUST be supported
The dossier view MUST show thumbnail previews for supported document types. Preview generation MUST leverage Nextcloud's built-in preview system.

#### Scenario: Show thumbnail for image files
- **GIVEN** informatieobject `foto-locatie.jpg` in the dossier
- **WHEN** the dossier view renders
- **THEN** a thumbnail preview MUST be shown using Nextcloud's preview API (`/index.php/core/preview?fileId={id}&x=64&y=64`)
- **AND** clicking the thumbnail MUST open a larger preview

#### Scenario: Show thumbnail for PDF files
- **GIVEN** informatieobject `aanvraag.pdf` in the dossier
- **WHEN** the dossier view renders and Nextcloud's PDF preview provider is enabled
- **THEN** a thumbnail of the first page MUST be shown
- **AND** clicking the thumbnail MUST open the PDF in Nextcloud's built-in viewer

#### Scenario: Show file type icon for unsupported preview types
- **GIVEN** informatieobject `data.xlsx` for which no preview is available
- **WHEN** the dossier view renders
- **THEN** a file type icon (spreadsheet icon) MUST be shown instead of a thumbnail
- **AND** clicking the icon MUST trigger a download

### Requirement: Document download API MUST support both single and batch downloads
The API MUST provide endpoints for downloading individual documents and batch downloads of multiple documents from a dossier.

#### Scenario: Download single document via API
- **GIVEN** informatieobject `besluit.pdf` with file ID 1234
- **WHEN** a client calls `GET /api/objects/{register}/{schema}/{objectId}/files/{fileId}/download`
- **THEN** the response MUST contain the file content with appropriate Content-Type and Content-Disposition headers
- **AND** the response MUST include `Content-Length` for the file size
- **AND** the endpoint MUST verify the user has access based on vertrouwelijkheidaanduiding

#### Scenario: Download document via ZGW DRC-compatible endpoint
- **GIVEN** ZGW API mapping is configured (see `zgw-api-mapping` spec)
- **WHEN** a client calls `GET /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download`
- **THEN** the response MUST return the binary file content
- **AND** the response MUST conform to the ZGW DRC download specification

#### Scenario: Stream large file download
- **GIVEN** informatieobject `groot-dossier.pdf` with file size 500 MB
- **WHEN** a client requests download
- **THEN** the response MUST be streamed (not buffered in memory) using `StreamResponse`
- **AND** the response MUST support HTTP Range requests for resumable downloads

### Requirement: Document operations MUST integrate with besluit documents
Documents linked to decisions (besluiten) MUST follow additional constraints as defined in the `besluiten-management` spec. Besluit documents MUST be definitief before the besluit can be published.

#### Scenario: Link besluit document to a decision
- **GIVEN** a besluit (decision) object `besluit-1` referencing zaak `vergunning-1`
- **WHEN** a document `beschikking.pdf` is linked to the besluit via `besluitinformatieobject`
- **THEN** the `besluitinformatieobject` join object MUST be created
- **AND** the document MUST appear in both the zaak dossier and the besluit detail view

#### Scenario: Enforce definitief status for besluit documents
- **GIVEN** a besluit `besluit-1` is ready for publication
- **AND** its linked document `beschikking.pdf` has `status` = `concept`
- **WHEN** the user attempts to publish the besluit
- **THEN** the system MUST block publication with error: "Alle gekoppelde documenten moeten status 'definitief' hebben"
- **AND** the user MUST be guided to finalize the concept documents first

#### Scenario: Include besluit documents in archival package
- **GIVEN** zaak `vergunning-1` has archiefnominatie `bewaren` (see `archivering-vernietiging` spec)
- **WHEN** the archival SIP package is generated
- **THEN** all informatieobjecten linked to the zaak MUST be included
- **AND** all informatieobjecten linked to the zaak's besluiten MUST also be included
- **AND** the SIP manifest MUST reference each document with its ZGW metadata

## Current Implementation Status
- **Implemented:**
  - `FileService` (`lib/Service/FileService.php`) provides the facade for all file operations including upload, download, tagging, sharing, versioning, and management
  - `FolderManagementHandler` (`lib/Service/File/FolderManagementHandler.php`) manages folder structures using the pattern `Open Registers/{Register Title} Register/{objectUuid}/`, with folder IDs stored on entities
  - `CreateFileHandler` (`lib/Service/File/CreateFileHandler.php`) handles file creation with base64 decoding, security validation, automatic object tagging, and upsert via `saveFile()`
  - `ReadFileHandler` (`lib/Service/File/ReadFileHandler.php`) retrieves files by ID, name, or path within object folders
  - `FileValidationHandler` (`lib/Service/File/FileValidationHandler.php`) blocks executable files by extension (50+ dangerous extensions) and magic byte detection (MZ, ELF, PHP, Java, shebangs)
  - `FileSharingHandler` (`lib/Service/File/FileSharingHandler.php`) creates public links, user shares, group shares, and folder shares via Nextcloud Share Manager
  - `FilePublishingHandler` (`lib/Service/File/FilePublishingHandler.php`) handles publish/unpublish workflows and ZIP archive creation for object files
  - `DocumentProcessingHandler` (`lib/Service/File/DocumentProcessingHandler.php`) replaces words in Word and text documents (used for anonymization)
  - `TaggingHandler` (`lib/Service/File/TaggingHandler.php`) attaches system tags to files, including automatic `object:{uuid}` tags
  - `FileFormattingHandler` (`lib/Service/File/FileFormattingHandler.php`) formats file metadata arrays with pagination, filtering, labels, tags, and share info
  - `FileOwnershipHandler` (`lib/Service/File/FileOwnershipHandler.php`) manages OpenRegister system user for file ownership
  - `UpdateFileHandler` (`lib/Service/File/UpdateFileHandler.php`) and `DeleteFileHandler` (`lib/Service/File/DeleteFileHandler.php`) for update and delete operations
  - `TextExtractionService` (`lib/Service/TextExtractionService.php`) extracts text from PDF (PdfParser), Word (PhpWord), and Excel (PhpSpreadsheet) documents
  - `FileTextExtractionJob` and `CronFileTextExtractionJob` (`lib/BackgroundJob/`) for async text extraction
  - `FileSearchController` (`lib/Controller/FileSearchController.php`) provides search endpoints for file content
  - `FileChangeListener` (`lib/Listener/FileChangeListener.php`) reacts to Nextcloud file change events
  - Frontend file views exist at `src/views/files/`
  - Objects can have associated files stored in Nextcloud Files with folder IDs tracked
- **NOT implemented:**
  - ZGW DRC-compliant `informatieobject` schema with full metadata (titel, vertrouwelijkheidaanduiding, auteur, status, informatieobjecttype, creatiedatum, bronorganisatie, taal, formaat, integriteit)
  - `zaakinformatieobject` join schema linking zaak objects to informatieobjecten
  - `besluitinformatieobject` join schema linking besluiten to informatieobjecten
  - `informatieobjecttype` catalog schema for document type definitions
  - Document status lifecycle enforcement (concept -> definitief -> gearchiveerd) with immutability on definitief
  - Vertrouwelijkheidaanduiding-based access control and visibility filtering
  - Structured dossier view with documents grouped by informatieobjecttype
  - Drag-and-drop upload with metadata dialog (informatieobjecttype, vertrouwelijkheidaanduiding)
  - Document version history display in dossier view (Nextcloud Files versioning exists but is not exposed in OpenRegister UI)
  - Full-text search within dossier scope (TextExtractionService exists but dossier-scoped search UI does not)
  - Bulk operations: bulk status transition, bulk metadata update
  - ZIP download with `manifest.csv` and informatieobjecttype folder structure (basic ZIP exists without manifest)
  - Document count badge on dossier tab
  - Bidirectional navigation from document to linked zaak objects
  - File size limits configurable per register
  - SHA-256 integrity hash on informatieobject (current system has no hashing)
  - Thumbnail/preview integration in dossier view
  - ZGW DRC-compatible download endpoints
  - Streaming large file downloads with Range request support
- **Partial:**
  - File upload and linking to objects works at a basic level via `CreateFileHandler.addFile()`
  - Folder structure exists (`Open Registers/{Register} Register/{uuid}/`) but without informatieobjecttype sub-folders
  - Nextcloud's native file versioning works but is not surfaced in OpenRegister's UI
  - ZIP archive creation exists in `FilePublishingHandler.createObjectFilesZip()` but without manifest or document-type folder structure
  - System tagging works via `TaggingHandler` but does not tag with informatieobjecttype
  - Text extraction works for PDF/Word/Excel but is not scoped to dossier search
  - File sharing works via `FileSharingHandler` but without vertrouwelijkheidaanduiding enforcement

## Standards & References
- **ZGW DRC (Documenten Registratie Component)** -- API standard for `enkelvoudiginformatieobject` registration in Dutch government (VNG Realisatie). Defines informatieobject data model, status lifecycle, vertrouwelijkheidaanduiding enumeration, and download endpoints.
- **ZGW ZTC (Zaaktypecatalogus)** -- Defines `informatieobjecttypen` (document type definitions) in the catalog, including default vertrouwelijkheidaanduiding per type.
- **ZGW BRC (Besluiten Registratie Component)** -- Defines `besluitinformatieobject` for linking documents to decisions. See `besluiten-management` spec.
- **MDTO (Metagegevens Duurzaam Toegankelijke Overheidsinformatie)** -- Archival metadata standard for documents. See `archivering-vernietiging` spec.
- **Archiefwet 1995 / Archiefbesluit 1995** -- Dutch archival law requiring retention schedules, destruction workflows, and legal holds for government documents.
- **NEN-ISO 16175-1:2020** -- International standard for records management principles (successor to NEN 2082).
- **CMIS (Content Management Interoperability Services)** -- OASIS standard for document management interoperability. CMIS compliance is a SHOULD for future integration with external DMS systems.
- **Nextcloud Files API (WebDAV / OCP\Files)** -- Underlying storage via `IRootFolder`, `IFile`, `Folder` interfaces. Versioning via `/dav/versions/`.
- **Nextcloud Share API (OCP\Share)** -- Share management via `IManager`, `IShare` for public links, user/group shares.
- **Nextcloud SystemTag API (OCP\SystemTag)** -- File tagging via `ISystemTagManager`, `ISystemTagObjectMapper`.
- **Nextcloud Preview API** -- Thumbnail generation via `/index.php/core/preview`.
- **WCAG 2.1 AA** -- Accessibility requirements for file upload dialogs, dossier views, and document previews.

## Cross-References
- **`zgw-api-mapping`** -- ZGW Documenten API endpoints are served by OpenRegister's mapping engine. The informatieobject schema properties map to ZGW DRC Dutch field names via Twig-based property mapping.
- **`besluiten-management`** -- Besluiten (decisions) reference informatieobjecten via `besluitinformatieobject`. Besluit publication requires all linked documents to have `status` = `definitief`.
- **`archivering-vernietiging`** -- Archived zaak dossiers include all linked informatieobjecten in SIP packages. Document `archiefstatus` transitions are coordinated with zaak archival lifecycle.
- **`audit-trail-immutable`** -- All document operations (upload, status change, publish, delete) MUST be recorded in the immutable audit trail.
- **`deletion-audit-trail`** -- Document deletion (of concept documents) MUST be recorded with the deleted document's metadata.

## Specificity Assessment
- The spec provides detailed scenarios for the full document lifecycle including ZGW DRC compliance, security validation, versioning, and bulk operations.
- Implementation paths are clear: existing handlers (`CreateFileHandler`, `FileValidationHandler`, `FileSharingHandler`, `FilePublishingHandler`, `TaggingHandler`, `TextExtractionService`) provide the foundation. New work centers on the informatieobject/zaakinformatieobject schemas, status lifecycle enforcement, vertrouwelijkheidaanduiding access control, and dossier UI.
- Open questions:
  1. Should informatieobjecttypen be stored as register objects (in a catalog register) or as schema configuration?
  2. How should vertrouwelijkheidaanduiding-based access control interact with Nextcloud's native sharing permissions?
  3. Should the manifest.csv in ZIP exports use ZGW Dutch field names or English field names?
  4. What is the maximum supported dossier size before pagination or lazy-loading becomes necessary in the dossier view?
  5. Should document-type sub-folders be the default or opt-in per register?

## Nextcloud Integration Analysis

**Status**: Partially implemented. Comprehensive file CRUD, folder management, sharing, tagging, text extraction, and ZIP creation exist. Missing: ZGW DRC metadata schemas, status lifecycle enforcement, vertrouwelijkheidaanduiding access control, dossier UI, and drag-and-drop with metadata dialog.

**Nextcloud Core Interfaces Used**:
- `IRootFolder` / `Folder` / `File` (OCP\Files): Core file storage via `FolderManagementHandler` and `CreateFileHandler`. Folder hierarchy: `Open Registers/{Register} Register/{objectUuid}/`.
- `IManager` / `IShare` (OCP\Share): File and folder sharing via `FileSharingHandler`. Supports TYPE_LINK (public), TYPE_USER, TYPE_GROUP shares.
- `ISystemTagManager` / `ISystemTagObjectMapper` (OCP\SystemTag): File tagging via `TaggingHandler`. Automatic `object:{uuid}` tags on upload.
- `IUserSession` / `IUser` (OCP\IUser): User context for file operations, ownership, and access control.
- `IURLGenerator` (OCP\IURLGenerator): Share link URL generation.
- `IConfig` (OCP\IConfig): Trusted domains for share URL construction.

**Implementation Approach**:
1. **Schema creation**: Define `informatieobject`, `zaakinformatieobject`, `besluitinformatieobject`, and `informatieobjecttype` schemas in the register JSON definition.
2. **Status lifecycle**: Implement status validation in `ObjectService` save hooks -- check current status, validate transition, enforce immutability for definitief documents by checking status before `CreateFileHandler.saveFile()`.
3. **Vertrouwelijkheidaanduiding**: Add access control middleware that checks user clearance level against document classification before serving files through `ReadFileHandler`.
4. **Dossier UI**: Build `DossierView.vue` component that queries `zaakinformatieobject` objects for the current zaak, fetches informatieobject metadata and file info, and renders grouped by informatieobjecttype.
5. **Drag-and-drop**: Use HTML5 Drag and Drop API in the dossier Vue component. On drop, show `DocumentMetadataDialog.vue` for type/classification selection before calling `CreateFileHandler.addFile()`.
6. **ZIP with manifest**: Extend `FilePublishingHandler.createObjectFilesZip()` to generate `manifest.csv` and organize files into informatieobjecttype sub-folders.

**Dependencies on Existing OpenRegister Features**:
- `FileService` facade -- orchestrates all file handler operations
- `CreateFileHandler` / `ReadFileHandler` / `UpdateFileHandler` / `DeleteFileHandler` -- CRUD operations
- `FolderManagementHandler` -- folder hierarchy management
- `FileValidationHandler` -- security validation (executable blocking, magic bytes)
- `FileSharingHandler` -- share link creation and user sharing
- `FilePublishingHandler` -- publish/unpublish workflows and ZIP creation
- `TaggingHandler` -- system tag management for file-object association
- `FileFormattingHandler` -- file metadata formatting with pagination
- `DocumentProcessingHandler` -- Word/text document transformation
- `TextExtractionService` -- full-text extraction from PDF/Word/Excel
- `ObjectService` -- object context for dossier association
