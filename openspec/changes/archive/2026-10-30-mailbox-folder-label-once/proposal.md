# Proposal: mailbox-folder-label-once

## Summary

DqBeheerinstellingen, Zaak-e-mail: the folder field read "Kaart" (a geographic map) where the board draws "Map". The fields translate their labels, and CnSettingsFieldsSection translates a label again through `cnTranslate`. "Folder" became "Map", and "Map" is also the English key for a geographic map, whose Dutch is "Kaart". The folder label is now handed to the section as its key, so it is translated once. A test checks that every other admin field label reads the same after the second pass.
