<?php

declare(strict_types=1);

namespace Bnomei;

final class Redirects
{
    /*
     * @var array
     */
    private $options;

    public function __construct(array $options = [])
    {
        $defaults = $this->defaultsFromConfig();
        $this->options = array_merge($defaults, $options);

        $this->checkForRedirect($this->options);
    }

    public function defaultsFromConfig(): array
    {
        $map = \option('bnomei.redirects.map', []);
        if (is_callable($map)) {
            $map = $map();
        }

        return [
            'code' => $this->normalizeCode(\option('bnomei.redirects.code')),
            'querystring' => \option('bnomei.redirects.querystring'),
            'map' => $map,
            'site.url' => site()->url(), // a) www.example.com or b) www.example.com/subfolder
            'request.uri' => $this->getRequestURI(),
        ];
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function checkForRedirect(array $options): ?array
    {
        $map = \Kirby\Toolkit\A::get($options, 'map');
        if (! $map || count($map) === 0) {
            return null;
        }

        $siteurl = \Kirby\Toolkit\A::get($options, 'site.url');
        $requesturi = \Kirby\Toolkit\A::get($options, 'request.uri');

        foreach ($map as $redirect) {
            if ($this->matchesFromUri($redirect, $requesturi, $siteurl)) {
                return [
                    'uri' => $this->validateToUri($redirect),
                    'code' => $this->validateCode($redirect, \Kirby\Toolkit\A::get($options, 'code')),
                ];
            }
        }
        return null;
    }

    public function matchesFromUri(array $redirect, string $requesturi, string $siteurl): bool
    {
        $sitebase = \Kirby\Http\Url::path($siteurl, true, true);
        $fromuri = \Kirby\Toolkit\A::get($redirect, 'fromuri');
        $fromuri = '/' . trim($sitebase . str_replace($siteurl, '', $fromuri), '/');
        return $fromuri === $requesturi;
    }

    private function getRequestURI(): string
    {
        $uri = array_key_exists("REQUEST_URI", $_SERVER) ? $_SERVER["REQUEST_URI"] : '/' . kirby()->request()->path();
        $uri = \option('bnomei.redirects.querystring') ? $uri : strtok($uri, '?'); // / or /page or /subfolder or /subfolder/page
        return $uri;
    }

    private function normalizeCode($code): int
    {
        return intval(str_replace('_', '', $code));
    }

    private function validateToUri($redirect): string
    {
        $touri = '/' . trim(\Kirby\Toolkit\A::get($redirect, 'touri'), '/');
        $page = page($touri);
        if ($page) {
            $touri = $page->url();
        } else {
            $touri = url($touri);
        }
        return $touri;
    }

    private function validateCode(array $redirect, int $optionsCode): int
    {
        $redirectCode = $this->normalizeCode(\Kirby\Toolkit\A::get($redirect, 'code'));
        if (! $redirectCode || $redirectCode === 0) {
            $redirectCode = $optionsCode;
        }
        return $redirectCode;
    }

    public static function redirects($options = [])
    {
        $redirects = new self($options);
        $check = $redirects->checkForRedirect(
            $redirects->getOptions()
        );
        if ($check && is_array($check)
            && array_key_exists('uri', $check)
            && array_key_exists('code', $check)
        ) {
            // @codeCoverageIgnoreStart
            \Kirby\Http\Header::redirect($check['uri'], $check['code']);
            // @codeCoverageIgnoreEnd
        }
    }

    public static function codes(bool $force = false): ?array
    {
        $codes = null;
        if (! $force && ! \option('debug')) {
            $codes = kirby()->cache('bnomei.redirects')->get('httpcodes');
        }
        if ($codes) {
            return $codes;
        }

        $codes = [];
        foreach (\Kirby\Http\Header::$codes as $code => $label) {
            $codes[] = [
                'code' => $code, // string: _302
                'label' => $label,
            ];
        }
        kirby()->cache('bnomei.redirects')->set('httpcodes', $codes, 60 * 24 * 7);

        return $codes;
    }
}
