<?php

declare(strict_types=1);

namespace Scedel\Validator;

use Scedel\ErrorCategory;
use Scedel\ErrorCode;

final readonly class ValidationError
{
    public function __construct(
        public string $path,
        public string $message,
        ErrorCode|string $code = ErrorCode::InvalidExpression,
        ErrorCategory|string $category = ErrorCategory::ValidationError,
    ) {
        $this->code = ErrorCode::coerce($code);
        $this->category = ErrorCategory::coerce($category);
    }

    public ErrorCode $code;
    public ErrorCategory $category;
}
