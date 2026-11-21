# signing-through-filinq

## Why

Filinq owns signing: its providers (LibreSign among them), its signer rails, its expiry and its
audit. Dossiq kept a LibreSign client of its own (`LibresignApiClient`, `LibresignSigningAdapter`,
`LibresignResultAssembler`, `LibresignStatusMapper`) behind `SigningAdapterInterface`, bound in
`BeschikkingAdapterRegistrar`, with `MockSigningAdapter` as the fallback. That adapter polled
LibreSign for a few seconds and gave up with "pending": a signature that came later never reached
the decision. Without LibreSign the mock adapter wrote a made-up "qualified" signature onto the
decision. Decision 221 moves signing to filinq; decision 182 says the code is named by what it
does, so the dossiq half is a signing request on a document, not a beschikking signer.

## What changes

- `Service/Signing/DocumentSigningRequester` raises filinq's `DocumentSigningRequestedEvent`
  (source app dossiq, the decision as subject, its file, the signer's user id, a signature level)
  and answers whether filinq took it, with filinq's request id. The level is the app config key
  `signing.level` (SES, AdES or QES; SES when unset). Filinq refuses a level its provider does not
  offer, and the request then reads "not taken".
- `BeschikkingService::onderteken` asks for the signature and returns at once (HTTP 202). The
  decision keeps its status and carries `signing.state = requested`. A second request while one is
  open is refused (409). Without filinq, or when filinq does not take the request, the answer is
  503 and nothing is written.
- `Listener/SigningConcludedListener` hears filinq's `SigningConcludedEvent` for dossiq's requests.
  Signed moves the decision to signed (signature with the level, filinq's request id and the
  signed document). Declined, cancelled and expired mark the request `not-concluded` with
  filinq's status; the decision keeps its status and can be asked again. Filinq concludes every
  request it took, at the latest when `signing_request_expiry_days` (30 by default) pass.
  The conclusion is written as the background service account (decision 242), never as the
  signer; with no usable service account nothing is written, the decision still reads `requested`
  and an error names the decision and the request for reconciliation.
- `ZgwDocumentService::storeRaw` goes: its only caller was the deleted LibreSign assembler.
- The audit packet's `validatierapport.json` says that no validation report is available,
  because filinq publishes none, rather than vouching for a signature nobody validated.
- Deleted: `SigningAdapterInterface`, the four LibreSign classes, `MockSigningAdapter`, the
  signing binding in `BeschikkingAdapterRegistrar` and their three test files. The settings
  answer `signingAvailable`/`signingHint` (filinq enabled) instead of
  `libresignAvailable`/`libresignHint`.
- The `beschikking` schema (1.2.0) gains `signing` and `signature.level`/`signature.signingRequestId`.

## Impact

Filinq must be installed for a decision to be signed. A decision already signed keeps its
signature; its old `validationRapportId` is named in the audit packet. Not covered: a signing
request still open in dossiq's old LibreSign flow (it never reached the decision before either).
