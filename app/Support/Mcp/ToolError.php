<?php

namespace App\Support\Mcp;

use RuntimeException;

/**
 * A tool input the server can't act on (unknown column, label, option…). The message is
 * written for the AI: it says what was wrong and how to find a valid value.
 */
class ToolError extends RuntimeException {}
