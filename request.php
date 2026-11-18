<?php

class CurlRequest {
    private $url;
    private $headers = [];
    private $timeout = null;
    private $authToken = null;
    private $cookie = null;
    private array $resolve = [];
    public function __construct($url) {
        global $request_exec_timeout;
        $this->url = $url;
        $this->timeout = $request_exec_timeout;
        $resolveFile = getenv('NOORVPN_PANEL_RESOLVE_FILE') ?: '/etc/noorvpn/panel-resolve.json';
        if (is_readable($resolveFile)) {
            $entries = json_decode((string) file_get_contents($resolveFile), true);
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
            if (is_array($entries)) {
                $matches = array_values(array_filter($entries, fn($entry) => is_string($entry) && str_starts_with($entry, "$host:$port:")));
                if ($matches) { $this->setResolve($matches); }
            }
        }
    }

    /** Preserve the TLS hostname while reaching a panel over loopback. */
    public function setResolve(array $entries): void {
        foreach ($entries as $entry) {
            if (!is_string($entry) || !preg_match('/^[a-zA-Z0-9.-]+:[0-9]{1,5}:127\.0\.0\.1$/D', $entry)) {
                throw new InvalidArgumentException('Only explicit loopback panel mappings are allowed');
            }
        }
        $this->resolve = $entries;
    }

    public function setHeaders(array $headers) {
        $this->headers = array_merge($this->headers, $headers);
    }

    public function setBearerToken($token) {
        $this->authToken = $token;
    }
    
    public function setCookie($cookieStr) {
        $this->cookie = $cookieStr;
    }

    private function prepareHeaders() {
        $headers = $this->headers;

        if ($this->authToken) {
            $headers[] = "Authorization: Bearer {$this->authToken}";
        }
        return $headers;
    }

    private function execute($method, $data = null) {
        $this->timeout = !$this->timeout  ?  10000 : $this->timeout;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min((int) $this->timeout, 5000));
        if ($this->resolve) {
            curl_setopt($ch, CURLOPT_RESOLVE, $this->resolve);
            curl_setopt($ch, CURLOPT_PROXY, '');
        }

        $finalHeaders = $this->prepareHeaders();
        if (!empty($finalHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);
        }
        if ($this->cookie) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie);   
        }
        if ($data) {
            if (is_array($data)) {
                $data = http_build_query($data);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            return [
                'status' => null,
                'body' => null,
                'error' => $error,
            ];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'status' => $httpCode,
            'body' => $response
        ];
    }

    public function get() {
        return $this->execute("GET");
    }

    public function post($data) {
        return $this->execute("POST", $data);
    }

    public function put($data) {
        return $this->execute("PUT", $data);
    }

    public function delete($data = null) {
        return $this->execute("DELETE", $data);
    }
    public function PATCH($data = null){
        return $this->execute('PATCH',$data);
    }
}