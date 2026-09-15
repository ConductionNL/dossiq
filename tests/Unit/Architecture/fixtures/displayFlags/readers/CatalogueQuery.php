<?php
// Reads the MIRROR, never the flag's own name. This is the D-4 case: a grep
// for `hiddenSomewhere` finds nothing here and the flag still has a reader.
$filters = ['hostHiddenHere' => 0];
