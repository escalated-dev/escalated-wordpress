<?php

namespace Escalated\Support;

/** A refused SSO sign-in. Its message is for the log, never for the user. */
class SsoException extends \RuntimeException {}
