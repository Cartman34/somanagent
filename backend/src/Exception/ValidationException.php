<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when one or more input fields fail validation.
 */
class ValidationException extends \RuntimeException
{
    /**
     * @param array<int, array{field: string, code: string}> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'Validation failed.')
    {
        parent::__construct($message);
    }

    /**
     * Returns the list of validation errors, each with a field name and an error code.
     *
     * @return array<int, array{field: string, code: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
