<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * REST controller serving UI translation keys to the frontend.
 */
#[Route('/api/ui')]
final class UiTranslationController extends AbstractController
{
    /**
     * Initializes the controller with the Symfony translator.
     */
    public function __construct(private readonly TranslatorInterface $translator) {}

    /**
     * Returns the requested UI translation keys for the current locale.
     */
    #[Route('/translations', name: 'ui_translations_api', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $domain = (string) ($request->query->get('domain') ?? 'app');
        $keys = $request->query->all('keys');

        if ($keys === []) {
            $raw = $request->query->get('keys');
            if (is_string($raw) && $raw !== '') {
                $keys = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $key) => $key !== ''));
            }
        }

        $translations = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $translations[$key] = $this->translator->trans($key, [], $domain);
        }

        return $this->json([
            'domain' => $domain,
            'locale' => $request->getLocale(),
            'translations' => $translations,
        ]);
    }
}
