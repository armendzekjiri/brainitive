<?php

namespace App\Support\Imports\Archive;

use RuntimeException;

/** The project is over an export limit; the message is shown to the person who exported it. */
class ArchiveTooLarge extends RuntimeException {}
