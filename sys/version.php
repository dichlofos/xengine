<?php
function xcms_version()
{
    // cache version for faster access
    global $XCMS_VERSION;
    if (xu_empty($XCMS_VERSION))
        $XCMS_VERSION = trim(file_get_contents("version"));
    return $XCMS_VERSION;
}
