<?php

declare(strict_types=1);

namespace SpomkyLabs\PwaBundle\ServiceWorkerRule;

use SpomkyLabs\PwaBundle\Dto\ServiceWorker;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class BackgroundFetchCache implements ServiceWorkerRuleInterface
{
    public function __construct(
        private ServiceWorker $serviceWorker,
        private RouterInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function process(bool $debug = false): string
    {
        if (!$this->serviceWorker->backgroundFetch->enabled) {
            return '';
        }
        $declaration = '';
            $successMessage = $this->serviceWorker->backgroundFetch->successMessage ?? '';
            if ($successMessage !== '') {
                $successMessage = sprintf("event.updateUI({ title: '%s' });", $this->translator->trans($successMessage, [], 'pwa'));
            }

            $declaration .= <<<BACKGROUND_FETCH_CACHE

// Background Fetch Cache
addEventListener('backgroundfetchsuccess', event => {
  event.waitUntil(
    (async () => {
      try {
        const cache = await caches.open('{$this->serviceWorker->backgroundFetch->cacheName}');
        const records = await event.registration.matchAll();
        const promises = records.map(async record => {
          const response = await record.responseReady;
          await cache.put(record.request, response);
        });
        await Promise.all(promises);
      } catch (err) {
        console.error(err)
      }
    })()
  );
});

BACKGROUND_FETCH_CACHE;

            if ($this->serviceWorker->backgroundFetch->successUrl !== null) {
                $successUrl = $this->router->generate($this->serviceWorker->backgroundFetch->successUrl->path, $this->serviceWorker->backgroundFetch->successUrl->params, $this->serviceWorker->backgroundFetch->successUrl->pathTypeReference);
                $declaration .= <<<BACKGROUND_FETCH_CACHE

addEventListener('backgroundfetchclick', (event) => {
  const bgFetch = event.registration;
  console.log('Background Fetch Cache: click');
  if (bgFetch.result !== 'success') {
    console.log('Background Fetch Cache: success');
    return;
  }
    console.log('Background Fetch Cache: openWindow {$successUrl}');
  clients.openWindow('{$successUrl}');
});

BACKGROUND_FETCH_CACHE;
            }

            if ($this->serviceWorker->backgroundFetch->progressUrl !== null) {
                $progressUrl = $this->router->generate($this->serviceWorker->backgroundFetch->progressUrl->path, $this->serviceWorker->backgroundFetch->progressUrl->params, $this->serviceWorker->backgroundFetch->progressUrl->pathTypeReference);
                $declaration .= <<<BACKGROUND_FETCH_CACHE

addEventListener('backgroundfetchclick', (event) => {
  const bgFetch = event.registration;
  console.log('Background Fetch Cache: click');
  if (bgFetch.result === 'success') {
    console.log('Background Fetch Cache: success');
    return;
  }
  console.log('Background Fetch Cache: openWindow {$progressUrl}');
  clients.openWindow('{$progressUrl}');
});

BACKGROUND_FETCH_CACHE;
            }

            if ($this->serviceWorker->backgroundFetch->successMessage !== null) {
                $successMessage = $this->serviceWorker->backgroundFetch->successMessage;
                if ($successMessage !== '') {
                    $successMessage = $this->translator->trans($successMessage, [], 'pwa');
                }
                $declaration .= <<<BACKGROUND_FETCH_CACHE

addEventListener("backgroundfetchsuccess", (event) => {
  event.updateUI({ title: "{$successMessage}" });
});

BACKGROUND_FETCH_CACHE;
            }

            if ($this->serviceWorker->backgroundFetch->failureMessage !== null) {
                $failureMessage = $this->serviceWorker->backgroundFetch->failureMessage;
                if ($failureMessage !== '') {
                    $failureMessage = $this->translator->trans($failureMessage, [], 'pwa');
                }
                $declaration .= <<<BACKGROUND_FETCH_CACHE

addEventListener("backgroundfetchfail", (event) => {
  event.updateUI({ title: "{$failureMessage}" });
});

BACKGROUND_FETCH_CACHE;
            }

        return $declaration;
    }

    public static function getPriority(): int
    {
        return 1024;
    }
}
