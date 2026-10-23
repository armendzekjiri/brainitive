<?php

namespace App\Support\Templates;

use RuntimeException;

/**
 * A template that can't be saved or used; the message is shown to the person.
 */
class TemplateException extends RuntimeException {}
