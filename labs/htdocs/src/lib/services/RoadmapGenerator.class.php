<?php
/**
 * Roadmap Generator Service
 * Generates structured roadmap content via Gemini API
 */
class RoadmapGenerator {

    private string $apiKey;
    private string $geminiUrl;

    public function __construct() {
        $this->apiKey = get_config('ai_api_key') ?? '';
        $this->geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
    }

    /**
     * Generate roadmap structure from a user prompt
     * @return array {title, slug, description, level, hours, tags, sections}
     */
    public function generateStructure(string $prompt, string $level = 'Beginner'): array {
        if (empty($this->apiKey)) {
            return $this->fallbackStructure($prompt, $level);
        }

        $aiPrompt = $this->buildStructurePrompt($prompt, $level);
        $response = $this->callGemini($aiPrompt, 4096);

        if ($response === null) {
            return $this->fallbackStructure($prompt, $level);
        }

        $parsed = json_decode($response, true);
        if (!$parsed || empty($parsed['title']) || empty($parsed['sections'])) {
            return $this->fallbackStructure($prompt, $level);
        }

        // Generate slug from title
        $parsed['slug'] = $this->generateSlug($parsed['title']);

        // Ensure required fields
        $parsed['level'] = $parsed['level'] ?? $level;
        $parsed['hours'] = (int)($parsed['hours'] ?? 20);
        $parsed['tags'] = $parsed['tags'] ?? [];
        $parsed['description'] = $parsed['description'] ?? '';

        // Generate IDs for sections, topics, items
        $parsed['sections'] = $this->assignIds($parsed['sections']);

        return $parsed;
    }

    /**
     * Generate content for a specific topic
     * @return array {content, content_html, resources}
     */
    public function generateTopicContent(string $topicTitle, string $sectionTitle, string $roadmapTitle): array {
        if (empty($this->apiKey)) {
            return ['content' => $this->fallbackContent($topicTitle), 'content_html' => '', 'resources' => []];
        }

        $aiPrompt = $this->buildTopicPrompt($topicTitle, $sectionTitle, $roadmapTitle);
        $response = $this->callGemini($aiPrompt, 2048, 'text/plain');

        if ($response === null) {
            return ['content' => $this->fallbackContent($topicTitle), 'content_html' => '', 'resources' => []];
        }

        $content = $this->unwrapJsonContent($response);
        $content = $this->sanitizeContent($content);
        $contentHtml = $this->markdownToHtml($content);

        return [
            'content' => $content,
            'content_html' => $contentHtml,
            'resources' => [],
        ];
    }

    /**
     * Generate content for a single item (concept, milestone, checkpoint, decision)
     */
    public function generateItemContent(string $itemText, string $itemType, string $topicTitle, string $sectionTitle, string $roadmapTitle): array {
        if (empty($this->apiKey)) {
            return ['content' => $this->fallbackItemContent($itemText, $itemType), 'content_html' => ''];
        }

        $aiPrompt = $this->buildItemPrompt($itemText, $itemType, $topicTitle, $sectionTitle, $roadmapTitle);
        $response = $this->callGemini($aiPrompt, 1024, 'text/plain');

        if ($response === null) {
            return ['content' => $this->fallbackItemContent($itemText, $itemType), 'content_html' => ''];
        }

        $content = $this->unwrapJsonContent($response);
        $content = $this->sanitizeContent($content);
        $contentHtml = $this->markdownToHtml($content);

        return [
            'content' => $content,
            'content_html' => $contentHtml,
        ];
    }

    /**
     * Build prompt for item-level content
     */
    private function buildItemPrompt(string $itemText, string $itemType, string $topicTitle, string $sectionTitle, string $roadmapTitle): string {
        $typeContext = '';
        if ($itemType === 'milestone') {
            $typeContext = 'This is a milestone — explain what the learner will be able to DO after mastering this.';
        } elseif ($itemType === 'checkpoint') {
            $typeContext = 'This is a hands-on checkpoint — explain what to DO and what to look for.';
        } elseif ($itemType === 'decision') {
            $typeContext = 'This is a decision point — explain the options and trade-offs.';
        } else {
            $typeContext = 'This is a concept — explain what it is and why it matters.';
        }

        return <<<PROMPT
You are an expert instructor. Write a SHORT explanation for this specific learning item.

Roadmap: "{$roadmapTitle}"
Section: "{$sectionTitle}"
Topic: "{$topicTitle}"
Item: "{$itemText}"
Type: {$itemType}

{$typeContext}

Write 2-3 SHORT sentences (max 60 words total). Be specific to THIS item, not generic.
Do NOT include any title or heading — just the explanation text.
Use plain text with **bold** for key terms only. No markdown headers.
PROMPT;
    }

    /**
     * Build the prompt for roadmap structure generation
     */
    private function buildStructurePrompt(string $prompt, string $level): string {
        return <<<PROMPT
You are an expert curriculum designer. Generate a structured learning roadmap.

User Request: "{$prompt}"
Difficulty Level: {$level}

Return ONLY valid JSON (no markdown, no code fences) with this exact structure:
{
  "title": "Concise roadmap title (max 60 chars, specific to the topic)",
  "description": "1-2 sentence roadmap description",
  "level": "{$level}",
  "hours": 45,
  "tags": ["tag1", "tag2", "tag3"],
  "sections": [
    {
      "title": "Section Title",
      "topics": [
        {
          "title": "Specific Topic Name",
          "items": [
            { "text": "Specific concept like 'Radio frequency (RF) basics'", "type": "concept" },
            { "text": "Skill assertion like 'You can explain the main factors affecting signal strength'", "type": "milestone" },
            { "text": "Hands-on task like 'Measure signal strength using a basic receiver'", "type": "checkpoint" },
            { "text": "Choice like 'Choose primary frequency band — weigh range vs interference'", "type": "decision" }
          ]
        }
      ]
    }
  ]
}

CRITICAL RULES:
1. Create 3-5 sections, each with 2-4 topics.
2. Each topic has 3-6 items mixing types: concept, milestone, checkpoint, decision.
3. Item text must be SPECIFIC and DESCRICTIVE — never generic like "Learn basics of X" or "Practice exercise".
4. Examples of GOOD item text:
   - concept: "Impedance matching and SWR (Standing Wave Ratio) basics"
   - concept: "Common FPV frequencies and bands (2.4 GHz, 5.8 GHz)"
   - milestone: "You can diagnose FPV signal issues and apply corrective steps"
   - milestone: "You can tune transmitter power settings to balance range and reliability"
   - checkpoint: "Run a checklist to identify signal interruptions and document findings"
   - checkpoint: "Modify VTX power setting and observe impact on image clarity"
   - decision: "Choose primary frequency band for your environment — weigh range vs interference"
   - decision: "Opt for omni vs directional antenna based on use case"
5. Examples of BAD item text (NEVER generate these):
   - "Understand the basics of X" (too vague)
   - "You can explain key X concepts" (generic)
   - "Practice with a hands-on exercise" (no specifics)
   - "Learn concept X" (lazy)
6. Titles must be unique and specific. Tags: 3-5 relevant keywords. Hours: 10-100.
7. Return ONLY the raw JSON object, nothing else.
PROMPT;
    }

    /**
     * Build the prompt for topic content generation
     */
    private function buildTopicPrompt(string $topicTitle, string $sectionTitle, string $roadmapTitle): string {
        return <<<PROMPT
You are an expert instructor. Write a concise explanation for the following topic within a learning roadmap.

Roadmap: "{$roadmapTitle}"
Section: "{$sectionTitle}"
Topic: "{$topicTitle}"

Write 2-3 SHORT paragraphs (max 150 words total). Include:
- What the topic covers and why it matters (1-2 sentences)
- Key concepts the learner should understand (3-5 bullet points)
- Practical context within the roadmap (1 sentence)

Return the content as clean Markdown text. Do NOT use HTML tags. Use ## for subheadings, - for bullet points, **bold** for emphasis. Be concise — no filler.
PROMPT;
    }

    /**
     * Call Gemini API and return the text response
     */
    private function callGemini(string $prompt, int $maxTokens = 2048, string $mimeType = 'application/json'): ?string {
        $url = $this->geminiUrl . '?key=' . urlencode($this->apiKey);

        $requestBody = json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => $maxTokens,
                'responseMimeType' => $mimeType,
            ]
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) return null;

        $geminiResp = json_decode($response, true);
        $text = $geminiResp['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($text));

        return !empty($text) ? $text : null;
    }

    /**
     * Generate URL-safe slug from title
     */
    private function generateSlug(string $title): string {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return substr($slug, 0, 80);
    }

    /**
     * Assign unique IDs to sections, topics, items
     */
    private function assignIds(array $sections): array {
        foreach ($sections as $si => &$section) {
            $section['id'] = 'sec_' . substr(sha1($section['title'] . $si), 0, 8);
            $section['order'] = $si + 1;
            if (!empty($section['topics'])) {
                foreach ($section['topics'] as $ti => &$topic) {
                    $topic['id'] = 'top_' . substr(sha1($topic['title'] . $ti), 0, 8);
                    $topic['order'] = $ti + 1;
                    $topic['content'] = null;
                    $topic['content_html'] = null;
                    $topic['resources'] = null;
                    if (!empty($topic['items'])) {
                        foreach ($topic['items'] as $ii => &$item) {
                            $item['id'] = 'item_' . substr(sha1($item['text'] . $ii), 0, 8);
                            $item['order'] = $ii + 1;
                            $item['type'] = $item['type'] ?? 'concept';
                        }
                        unset($item);
                    }
                }
                unset($topic);
            }
        }
        unset($section);
        return $sections;
    }

    /**
     * Sanitize AI-generated content
     */
    private function sanitizeContent(string $text): string {
        // Remove any HTML tags that Gemini might produce
        $text = strip_tags($text);
        // Escape for safe storage
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        // Decode back since we want the content readable
        $text = htmlspecialchars_decode($text, ENT_QUOTES);
        return $text;
    }

    /**
     * Unwrap JSON content if Gemini returned JSON instead of plain text
     * Handles: {"explanation":"..."}, {"content":"..."}, {"text":"..."}, or raw string
     */
    private function unwrapJsonContent(string $text): string {
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) return $text;

        // Try common keys Gemini might use
        foreach (['explanation', 'content', 'text', 'body', 'response'] as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                return $decoded[$key];
            }
        }

        // If it's an array with a single string value, use that
        if (count($decoded) === 1) {
            $val = reset($decoded);
            if (is_string($val)) return $val;
        }

        return $text;
    }

    /**
     * Simple Markdown to HTML conversion
     */
    private function markdownToHtml(string $md): string {
        // Headers
        $md = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $md);
        $md = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $md);
        $md = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $md);
        // Bold
        $md = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md);
        // Italic
        $md = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $md);
        // Unordered lists
        $md = preg_replace('/^- (.+)$/m', '<li>$1</li>', $md);
        $md = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $md);
        // Paragraphs
        $md = preg_replace('/\n\n/', '</p><p>', $md);
        $md = '<p>' . $md . '</p>';
        // Clean up empty tags
        $md = preg_replace('/<p>\s*<\/p>/', '', $md);
        return $md;
    }

    /**
     * Fallback structure when AI is unavailable
     */
    private function fallbackStructure(string $prompt, string $level): array {
        $words = preg_split('/[\s,.\-;:!?]+/', $prompt);
        $stopWords = ['i', 'me', 'the', 'and', 'for', 'with', 'that', 'this', 'from', 'have', 'are', 'want', 'how', 'can', 'to', 'learn', 'about', 'please', 'generate', 'create'];
        $coreWords = [];
        foreach ($words as $w) {
            $w = trim($w);
            if (!in_array(strtolower($w), $stopWords) && strlen($w) > 2) {
                $coreWords[] = ucfirst(strtolower($w));
            }
        }
        $subject = implode(' ', array_slice(array_unique($coreWords), 0, 5)) ?: 'Technology';

        $sections = [];
        $sectionNames = ['Fundamentals', 'Core Concepts', 'Practical Application'];
        foreach ($sectionNames as $i => $secName) {
            $topics = [];
            for ($t = 0; $t < 3; $t++) {
                $topics[] = [
                    'title' => "{$subject} - {$secName} Topic " . ($t + 1),
                    'items' => [
                        ['text' => "Understand the basics of {$subject}", 'type' => 'concept'],
                        ['text' => "You can explain key {$subject} concepts", 'type' => 'milestone'],
                        ['text' => "Practice with a hands-on exercise", 'type' => 'checkpoint'],
                    ]
                ];
            }
            $sections[] = ['title' => ($i + 1) . ". {$secName}", 'topics' => $topics];
        }

        return [
            'title' => $subject . ' Learning Path',
            'slug' => $this->generateSlug($subject . '-learning-path'),
            'description' => "A structured roadmap for learning {$subject}.",
            'level' => $level,
            'hours' => 30,
            'tags' => array_map('strtolower', array_slice(explode(' ', $subject), 0, 3)),
            'sections' => $this->assignIds($sections),
        ];
    }

    /**
     * Fallback content when AI is unavailable
     */
    private function fallbackContent(string $topicTitle): string {
        return "## {$topicTitle}\n\nThis topic covers the essential concepts and practical skills needed. Study the key ideas, complete the hands-on checkpoints, and test your understanding before moving on.";
    }

    private function fallbackItemContent(string $itemText, string $itemType): string {
        if ($itemType === 'milestone') return "Mastering this means you can confidently {$itemText}.";
        if ($itemType === 'checkpoint') return "Complete this hands-on task: {$itemText}.";
        if ($itemType === 'decision') return "Consider the trade-offs when {$itemText}.";
        return "{$itemText} — a key concept to understand in this area.";
    }

    /**
     * Convert roadmap structure to readable markdown
     */
    public function structureToMarkdown(array $structure): string {
        $md = "# " . ($structure['title'] ?? 'Untitled') . "\n";
        $md .= "> " . ($structure['description'] ?? '') . "\n";
        $md .= "`tags: " . implode(', ', $structure['tags'] ?? []) . "`\n";
        $md .= "`hours: " . ($structure['hours'] ?? 0) . "`\n\n";

        foreach ($structure['sections'] ?? [] as $section) {
            $md .= "## " . ($section['title'] ?? '') . "\n\n";
            foreach ($section['topics'] ?? [] as $topic) {
                $md .= "### " . ($topic['title'] ?? '') . "\n";
                foreach ($topic['items'] ?? [] as $item) {
                    $type = $item['type'] ?? 'concept';
                    $text = $item['text'] ?? '';
                    if ($type === 'milestone') {
                        $md .= "- **" . $text . "**\n";
                    } elseif ($type === 'checkpoint') {
                        $md .= "- [ ] " . $text . "\n";
                    } elseif ($type === 'decision') {
                        $md .= "- (decision) " . $text . "\n";
                    } else {
                        $md .= "- " . $text . "\n";
                    }
                }
                $md .= "\n";
            }
        }
        return $md;
    }
}
