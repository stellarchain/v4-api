<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CursorPaginationMetaSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!preg_match('#^/v1/contracts/[^/]+/(events|transactions|storage|argument-usages|balances|holder-balances)$#', $path)) {
            return;
        }

        $meta = $request->attributes->get('_cursor_meta');
        if (!is_array($meta)) {
            return;
        }

        $response = $event->getResponse();
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'json')) {
            return;
        }

        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return;
        }

        $decoded['meta'] = $meta;
        $response->setContent((string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
