<?php

namespace App\Services;

use RuntimeException;

class NotConnectedException extends RuntimeException
{
    public function __construct(string $message = 'Not connected to YouTube. Please log in with Google first.')
    {
        parent::__construct($message);
    }
}
