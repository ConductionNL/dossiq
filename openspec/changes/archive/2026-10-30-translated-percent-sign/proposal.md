# Proposal: translated-percent-sign

## Why

DqDoorlooptijd's Monthly SLA trend card showed "Report generation failed", and the page's CSV download failed too.
Nextcloud runs every translated text through vsprintf(), also when no parameters are passed, so a bare `%` in a
translated text ("SLA Compliance %", "Within the norm (%)") throws and takes the whole response with it.

## What changes

The two texts write their percent sign as `%%`, in the source and in every catalogue. An architecture test scans lib/
for texts translated without parameters and fails when one, or its Dutch translation, does not survive vsprintf().
The test doubles of IL10N in the two affected tests now format like Nextcloud does, so they would have caught it.

## Scope

DoorlooptijdController (CSV header), ProcessingTimeSeriesPublisher (trend series name), l10n catalogues. The front end
keeps its own "SLA Compliance %" key, which is not formatted.
