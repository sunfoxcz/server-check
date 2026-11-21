<?php

namespace Sunfox\ServerCheck;

use RuntimeException;

final class HttpClient
{
    /** @return array{status: int, contentType: string, body: string} */
    public function get($url, array $query, $timeout)
    {
        $url = substr($url, 0, strcspn($url, '#'));
        if ($query !== []) {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $url .= $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->request($url, $timeout, []);
    }

    /** @return array{status: int, contentType: string, body: string} */
    public function postXml($url, $body, $timeout, $username = null, $password = null)
    {
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($username !== null) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERNAME] = $username;
            $options[CURLOPT_PASSWORD] = $password;
        }

        return $this->request($url, $timeout, $options);
    }

    private function request($url, $timeout, array $options)
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('HTTP check URL must use http or https');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize the HTTP request');
        }

        $options += [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_ENCODING => '',
        ];
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if (!curl_setopt_array($handle, $options)) {
            throw new RuntimeException('Could not configure the HTTP request');
        }

        $body = curl_exec($handle);
        if ($body === false) {
            $code = curl_errno($handle);
            // The full transport error may contain credentials from the request URL.
            throw new RuntimeException(sprintf('HTTP request failed (cURL %d: %s)', $code, curl_strerror($code)));
        }

        return [
            'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'contentType' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
            'body' => $body,
        ];
    }
}
