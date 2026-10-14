<?php

namespace App\Exception;

use Exception;

class UrlNotFoundException extends Exception
{
    public function __construct(string $message = 'URL not found')
    {
        parent::__construct($message, 404);
    }
}
