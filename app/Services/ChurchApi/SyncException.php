<?php

namespace App\Services\ChurchApi;

use RuntimeException;

/** Message is written in plain language and is safe to show to an administrator. */
class SyncException extends RuntimeException
{
}