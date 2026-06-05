<?php namespace ProcessWire;

/**
 * AI integration for Arbor — all calls routed through the AiWire module.
 *
 * Public surface:
 *   - parseText($text): structured person + events + names extracted from prose.
 *   - parseImage($filePath): OCR + classification of an archival document scan.
 *   - findDuplicates($personId): scores existing persons for likely duplicates.
 *   - historicalContext($personRow): 2-3 sentence biographical context.
 */
class ArborAi extends Wire
{
    protected Arbor $arbor;

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function enabled(): bool
    {
        return (bool) $this->arbor->aiEnabled && $this->wire('modules')->isInstalled('AiWire');
    }

    public function parseText(string $text): array
    {
        if (!$this->enabled()) return [];
        $prompt = $this->arbor->aiParsePrompt ?: $this->defaultParsePrompt();
        $prompt = str_replace('{input}', $text, $prompt);
        $json = $this->callAiwire('text', ['prompt' => $prompt, 'format' => 'json']);
        return $this->safeJsonDecode($json);
    }

    public function parseImage(string $imagePath): array
    {
        if (!$this->enabled()) return [];
        if (!is_file($imagePath)) return [];
        $prompt = "Examine this archival document scan and return JSON with:\n"
            . "doc_type (one of: metrical_book, revision_list, census, page_of_testimony, military, other),\n"
            . "archive_name, fond, opis, delo, list_folio,\n"
            . "persons: array of {given, surname, patronymic, given_hebrew, age, sex},\n"
            . "events: array of {event_type, event_date, event_place, fields},\n"
            . "transcription (full text), notes.\n"
            . "Return JSON only.";
        $json = $this->callAiwire('vision', [
            'prompt' => $prompt, 'format' => 'json', 'image' => $imagePath,
        ]);
        return $this->safeJsonDecode($json);
    }

    public function findDuplicates(int $personId): array
    {
        if (!$this->enabled()) return [];
        $arbor = $this->arbor;
        $subject = $arbor->model('persons')->get($personId);
        if (!$subject) return [];
        $subjectName = $arbor->model('names')->primary($personId);
        $candidates = $arbor->model('persons')->findByTree((int) $subject['tree_id'], ['limit' => 500]);
        $list = [];
        foreach ($candidates as $c) {
            if ((int) $c['id'] === $personId) continue;
            $list[] = ['id' => (int) $c['id'], 'name' => trim(($c['given'] ?? '') . ' ' . ($c['surname'] ?? ''))];
        }
        $payload = [
            'subject' => [
                'name' => trim(($subjectName['given'] ?? '') . ' ' . ($subjectName['surname'] ?? '')),
                'patronymic' => $subjectName['patronymic'] ?? '',
                'sex' => $subject['sex'],
            ],
            'candidates' => $list,
        ];
        $prompt = "Return JSON array of likely duplicates as [{person_id, similarity (0-1), reason}].\n"
            . "Subject:\n" . json_encode($payload['subject']) . "\n"
            . "Candidates:\n" . json_encode($payload['candidates']);
        $json = $this->callAiwire('text', ['prompt' => $prompt, 'format' => 'json']);
        $arr = $this->safeJsonDecode($json);
        return is_array($arr) ? $arr : [];
    }

    public function historicalContext(array $person): string
    {
        if (!$this->enabled()) return '';
        $events = $this->arbor->model('events')->forPerson((int) $person['id']);
        $birth = null; $death = null;
        foreach ($events as $e) {
            if ($e['event_type'] === 'birth' && !$birth) $birth = $e;
            if ($e['event_type'] === 'death' && !$death) $death = $e;
        }
        $prompt = sprintf(
            "Provide 2-3 sentences of factual historical context for a person born %s in %s, died %s in %s, ethnicity %s, religion %s. No invented biographical facts.",
            $birth['event_date'] ?? 'unknown', $birth['event_place_str'] ?? 'unknown',
            $death['event_date'] ?? 'unknown', $death['event_place_str'] ?? 'unknown',
            $person['ethnicity'] ?: 'unknown', $person['religion'] ?: 'unknown'
        );
        return '[AI] ' . trim((string) $this->callAiwire('text', ['prompt' => $prompt]));
    }

    protected function callAiwire(string $mode, array $params): string
    {
        $modules = $this->wire('modules');
        if (!$modules->isInstalled('AiWire')) return '';
        $aiwire = $modules->get('AiWire');
        $provider = $this->arbor->aiProvider ?: null;
        try {
            if (method_exists($aiwire, 'generate')) {
                return (string) $aiwire->generate($params['prompt'], [
                    'provider' => $provider,
                    'mode' => $mode,
                    'image' => $params['image'] ?? null,
                    'format' => $params['format'] ?? 'text',
                ]);
            }
            if (method_exists($aiwire, 'ask')) {
                return (string) $aiwire->ask($params['prompt'], $provider);
            }
        } catch (\Throwable $e) {
            $this->wire('log')->save('arbor', 'AI call failed: ' . $e->getMessage());
        }
        return '';
    }

    protected function safeJsonDecode(string $json): array
    {
        if ($json === '') return [];
        if (preg_match('/\{.*\}|\[.*\]/s', $json, $m)) $json = $m[0];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    protected function defaultParsePrompt(): string
    {
        return "Extract genealogical data from the following text and return JSON only.\n"
            . "Fields: names (array of {name_type, given, surname, patronymic, given_hebrew, script}),\n"
            . "sex, ethnicity, religion, is_cohen, is_levi,\n"
            . "citizenships (array of {country, date_from, date_to}),\n"
            . "events (array of {event_type, title, event_date, event_date_phrase, event_place, event_date_cal, description, fields (object of type-specific keys)}),\n"
            . "associations (array of {role, role_phrase, related_name}).\n"
            . "If uncertain, set null. Do not invent data. Do not guess dates.\n"
            . "Text: {input}";
    }
}
