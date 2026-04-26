<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Service\ApiErrorPayloadFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base API controller providing shared DTO parsing with automatic validation error handling.
 */
abstract class AbstractApiController extends AbstractController
{
    /**
     * Initializes the base controller with the shared error payload factory.
     */
    public function __construct(
        protected readonly ApiErrorPayloadFactory $apiErrorPayloadFactory,
    ) {}

    /**
     * Parses a DTO via the given factory, returning a 422 JsonResponse on ValidationException.
     *
     * @template T
     * @param callable(): T $factory
     * @return T|JsonResponse
     */
    protected function tryParseDto(callable $factory): mixed
    {
        try {
            return $factory();
        } catch (ValidationException $e) {
            return $this->json(
                $this->apiErrorPayloadFactory->fromValidationException($e),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }
}
