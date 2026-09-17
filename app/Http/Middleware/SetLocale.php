<?php

namespace App\Http\Middleware;

use App\Services\TranslationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    protected $translationService;

    public function __construct(TranslationService $translationService)
    {
        $this->translationService = $translationService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestedLocale = settings()->get('application.locale', config('app.locale'));
        $requestedLocale = is_string($requestedLocale) ? $requestedLocale : null;

        $locale = $this->translationService->resolveLocale($requestedLocale)
            ?? $this->translationService->resolveLocale((string) config('app.fallback_locale', 'en_US'))
            ?? $this->translationService->resolveLocale((string) config('app.locale', 'en_US'))
            ?? $this->translationService->resolveLocale('en_US');

        if ($locale === null) {
            $availableLocales = $this->translationService->getAvailableLocales();
            $locale = array_key_first($availableLocales);
        }

        if (is_string($locale) && $locale !== '') {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
