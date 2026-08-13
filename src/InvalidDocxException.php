<?php

declare(strict_types=1);

namespace PrintScript;

/** De aangeleverde bytes zijn geen leesbaar .docx-pakket. */
class InvalidDocxException extends \InvalidArgumentException
{
}
