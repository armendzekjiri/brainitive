<?php

namespace App\Support\Auth;

use RuntimeException;

/** A refused Apple sign-in; the message is written for the user (shown on the login page). */
final class AppleLoginFailed extends RuntimeException {}
