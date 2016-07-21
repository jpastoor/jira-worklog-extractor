<?php

namespace Jpastoor\JiraWorklogExtractor;

use chobie\Jira\Api;
use chobie\Jira\Api\Client\ClientInterface;

/**
 * Class CachedHttpClient
 *
 * Acts as a caching proxy
 *
 * @package Jpastoor\JiraWorklogExtractor\Command
 * @author Joost Pastoor <joost.pastoor@munisense.com>
 * @copyright Copyright (c) 2016, Munisense BV
 */
class CachedHttpClient implements ClientInterface
{
    /** @var string */
    private $cache_dir;

    /** @var ClientInterface */
    private $client;

    /**
     * CachedHttpClient constructor.
     *
     * @param ClientInterface $client
     * @param string $cache_dir
     */
    public function __construct(ClientInterface $client, $cache_dir = null)
    {
        if ($cache_dir == null) {
            $cache_dir = __DIR__ . "/../cache/";
        }

        $this->cache_dir = $cache_dir;
        $this->client = $client;
    }


    /**
     * Sends request to the API server.
     *
     * @param string $method Request method.
     * @param string $url URL.
     * @param array|string $data Request data.
     * @param string $endpoint Endpoint.
     * @param Api\Authentication\AuthenticationInterface $credential Credential.
     * @param boolean $is_file This is a file upload request.
     * @param boolean $debug Debug this request.
     *
     * @return array|string
     * @throws \InvalidArgumentException When non-supported implementation of AuthenticationInterface is given.
     * @throws \InvalidArgumentException When data is not an array and http method is GET.
     * @throws \chobie\Jira\Api\Exception When request failed due communication error.
     * @throws \chobie\Jira\Api\UnauthorizedException When request failed, because user can't be authorized properly.
     * @throws \chobie\Jira\Api\Exception When there was empty response instead of needed data.
     */
    public function sendRequest(
        $method,
        $url,
        $data = [],
        $endpoint,
        Api\Authentication\AuthenticationInterface $credential,
        $is_file = false,
        $debug = false
    ) {
        // We only do GET methods
        if ($method == Api::REQUEST_GET) {
            $cache_id = md5($url) . md5(json_encode($data)) . md5($endpoint) . ".json";
            $file = $this->cache_dir . "/" . $cache_id;

            // When the file does not exist, send request and store the result
            if (!file_exists($file)) {
                $contents = $this->client->sendRequest($method, $url, $data, $endpoint, $credential, $is_file, $debug);
                file_put_contents($file, json_encode($contents));

                return $contents;
            } else {
                // When the file exists then we have a cache hit, retrieve and return
                return json_decode(file_get_contents($file));
            }
        }

        // Non-GET methods get forwarded
        return $this->client->sendRequest($method, $url, $data, $endpoint, $credential, $is_file, $debug);
    }

    /**
     * Clear the entire cache directory
     * 
     * @return int Amount of cache files cleared
     */
    public function clear()
    {
        $i = 0;
        $files = glob($this->cache_dir . "/*"); // get all file names
        foreach ($files as $file) { // iterate files
            if (is_file($file)) {
                // delete file
                unlink($file);
                $i++;
            }
        }

        return $i;
    }
}
