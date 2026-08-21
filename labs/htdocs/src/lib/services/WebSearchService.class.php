<?php
/**
 * Web Search Service - Fetches free learning resources
 * Uses DuckDuckGo Instant Answer API (free, no key required)
 */
class WebSearchService {

    private const DDG_API = 'https://api.duckduckgo.com/';
    private const TIMEOUT = 5;
    private const MAX_RESULTS = 8;

    /**
     * Search for free learning resources related to a topic
     * @param string $query The search query
     * @param int $limit Max results to return
     * @return array List of {type, title, url, source}
     */
    public function searchResources(string $query, int $limit = 8): array {
        $results = [];

        // Method 1: DuckDuckGo Instant Answer API
        $ddgResults = $this->searchDDG($query);
        $results = array_merge($results, $ddgResults);

        // Method 2: DuckDuckGo HTML search for additional results
        if (count($results) < $limit) {
            $htmlResults = $this->searchDDGHTML($query, $limit - count($results));
            $results = array_merge($results, $htmlResults);
        }

        // Deduplicate by URL
        $seen = [];
        $unique = [];
        foreach ($results as $r) {
            $url = $r['url'] ?? '';
            if (!empty($url) && !isset($seen[$url])) {
                $seen[$url] = true;
                $unique[] = $r;
            }
        }

        return array_slice($unique, 0, $limit);
    }

    /**
     * DuckDuckGo Instant Answer API search
     */
    private function searchDDG(string $query): array {
        $results = [];
        $params = http_build_query([
            'q' => $query,
            'format' => 'json',
            'no_html' => 1,
            'skip_disambig' => 1,
        ]);

        $url = self::DDG_API . '?' . $params;
        $response = $this->httpGet($url);

        if (!$response) return $results;

        // Extract from Abstract
        if (!empty($response['AbstractURL']) && !empty($response['Abstract'])) {
            $results[] = [
                'type' => 'article',
                'title' => $response['Heading'] ?? $query,
                'url' => $response['AbstractURL'],
                'source' => $response['AbstractSource'] ?? '',
            ];
        }

        // Extract from RelatedTopics
        if (!empty($response['RelatedTopics']) && is_array($response['RelatedTopics'])) {
            foreach ($response['RelatedTopics'] as $topic) {
                if (count($results) >= self::MAX_RESULTS) break;

                // Direct topic with URL
                if (!empty($topic['FirstURL']) && !empty($topic['Text'])) {
                    $results[] = [
                        'type' => $this->guessType($topic['Text']),
                        'title' => $this->cleanTitle($topic['Text']),
                        'url' => $topic['FirstURL'],
                        'source' => $this->extractDomain($topic['FirstURL']),
                    ];
                }

                // Sub-topics (e.g., disambiguation)
                if (!empty($topic['Topics']) && is_array($topic['Topics'])) {
                    foreach ($topic['Topics'] as $sub) {
                        if (count($results) >= self::MAX_RESULTS) break;
                        if (!empty($sub['FirstURL']) && !empty($sub['Text'])) {
                            $results[] = [
                                'type' => $this->guessType($sub['Text']),
                                'title' => $this->cleanTitle($sub['Text']),
                                'url' => $sub['FirstURL'],
                                'source' => $this->extractDomain($sub['FirstURL']),
                            ];
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * DuckDuckGo HTML search fallback
     */
    private function searchDDGHTML(string $query, int $limit): array {
        $results = [];
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query . ' tutorial guide');
        $html = $this->httpGetRaw($url);

        if (!$html) return $results;

        // Extract result links from HTML
        $pattern = '/<a[^>]+class="result__a"[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s';
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $i => $match) {
                if ($i >= $limit) break;
                $href = trim($match[1]);
                $title = strip_tags($match[2]);

                // DuckDuckGo wraps URLs in redirect — extract actual URL
                if (preg_match('/uddg=([^&]+)/', $href, $urlMatch)) {
                    $href = urldecode($urlMatch[1]);
                }

                if ($this->isValidUrl($href)) {
                    $results[] = [
                        'type' => $this->guessType($title),
                        'title' => trim($title),
                        'url' => $href,
                        'source' => $this->extractDomain($href),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Validate URL is http/https only
     */
    private function isValidUrl(string $url): bool {
        return preg_match('/^https?:\/\//i', $url) === 1;
    }

    /**
     * Extract domain name from URL
     */
    private function extractDomain(string $url): string {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return '';
        // Remove www. prefix
        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Guess resource type from text content
     */
    private function guessType(string $text): string {
        $text = strtolower($text);
        if (strpos($text, 'video') !== false || strpos($text, 'watch') !== false || strpos($text, 'youtube') !== false) {
            return 'video';
        }
        if (strpos($text, 'course') !== false || strpos($text, 'learn') !== false) {
            return 'course';
        }
        return 'article';
    }

    /**
     * Clean title text from snippet
     */
    private function cleanTitle(string $text): string {
        // Truncate at first period or dash if too long
        $text = preg_split('/[\.\-–—]/', $text);
        $title = trim($text[0]);
        // Limit length
        if (mb_strlen($title) > 100) {
            $title = mb_substr($title, 0, 97) . '...';
        }
        return $title;
    }

    /**
     * HTTP GET with timeout, returns decoded JSON
     */
    private function httpGet(string $url): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) return null;
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * HTTP GET returns raw string
     */
    private function httpGetRaw(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TomLabs/1.0)',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($response && $httpCode === 200) ? $response : null;
    }
}
