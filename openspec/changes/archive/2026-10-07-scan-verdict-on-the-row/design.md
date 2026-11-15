# Design: scan-verdict-on-the-row

## D-1. Read, never infer

The verdict is read from what `files_antivirus` recorded for the file id,
through its public service when the app is installed. Three states: clean
(with time), infected, not scanned. Absent app is not scanned. Clean is
never assumed.

## D-2. Column when the browser reads it

The manifest declares the Scan column with the formatter now;
`files-browser-columns` (nextcloud-vue) renders it when it lands. Until
then the verdict is in the properties dialog.
