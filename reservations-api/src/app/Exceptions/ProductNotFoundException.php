<?php

namespace App\Exceptions;

use RuntimeException;

class ProductNotFoundException extends RuntimeException
{
    public function __construct(public readonly int $productId)
    {
        parent::__construct("Product [{$productId}] was not found.");
    }
}
