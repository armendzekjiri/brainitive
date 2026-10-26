<?php

namespace App\Support\Imports;

use RuntimeException;

/**
 * A problem the user can act on ("This isn't a Trello board export", "The file has more than 5 000
 * cards"). Its message is shown as is; any other exception is shown as a generic failure.
 */
class ImportFailed extends RuntimeException {}
