<?php namespace ProcessWire;

/**
 * REST API for the Arbor module.
 *
 * Routes are dispatched from the page hook bound to the configured base path
 * (default /api/arbor/). Reads return JSON without a body content type; writes
 * accept JSON request bodies and require authenticated users + CSRF for cookie
 * sessions or a Bearer token for non-cookie clients.
 */
class ArborApi extends WireData implements Module, ConfigurableModule
{
    public static function getModuleInfo(): array
    {
        return [
            'title'    => 'Arbor REST API',
            'version'  => 100,
            'summary'  => 'JSON REST API for the Arbor genealogy module',
            'icon'     => 'cloud',
            'requires' => ['Arbor'],
            'autoload' => true,
            'singular' => true,
        ];
    }

    public static function getDefaultConfig(): array
    {
        return [
            'enabled'          => 1,
            'apiBase'          => '/api/arbor/',
            'hideLiving'       => 1,
            'livingThreshold'  => 110,
            'corsOrigin'       => '*',
            'requireAuthReads' => 0,
        ];
    }

    public function __construct()
    {
        parent::__construct();
        foreach (self::getDefaultConfig() as $k => $v) $this->set($k, $v);
    }

    protected Arbor $arbor;

    public function init(): void
    {
        $this->arbor = $this->wire('modules')->get('Arbor');
        if (!$this->enabled) return;   // API is switched off — skip route hook
        $this->addHookBefore('ProcessPageView::execute', $this, 'route');
    }

    public function route(HookEvent $event): void
    {
        $base = '/' . trim($this->apiBase, '/') . '/';
        $uri = '/' . ltrim($_SERVER['REQUEST_URI'] ?? '/', '/');
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        if (strpos($path, $base) !== 0) return;
        $event->replace = true;
        $event->return = '';

        try {
            $segments = array_values(array_filter(explode('/', substr($path, strlen($base)))));
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $this->dispatch($method, $segments);
        } catch (Wire404Exception $e) {
            $this->respond(['error' => 'Not found'], 404);
        } catch (\Throwable $e) {
            $this->respond(['error' => $e->getMessage()], 500);
        }
        exit;
    }

    protected function dispatch(string $method, array $segments): void
    {
        $entity = $segments[0] ?? '';
        $id     = isset($segments[1]) ? (int) $segments[1] : 0;
        $sub    = $segments[2] ?? '';

        if ($method === 'GET') {
            switch ($entity) {
                case '':
                    $this->respond($this->endpointCatalog()); return;
                case 'trees':
                    if (!$id) { $this->getTrees(); return; }
                    if ($sub === 'graph') { $this->getTreeGraph($id); return; }
                    $this->getTree($id); return;
                case 'persons':
                    if (!$id) throw new Wire404Exception();
                    $this->getPerson($id, $sub); return;
                case 'places':
                    if (!$id) throw new Wire404Exception();
                    $this->getPlace($id, $sub); return;
            }
        }

        $this->requireAuth($method);

        if (in_array($method, ['POST','PUT','DELETE'], true)) {
            $body = $this->parseBody();
            switch ($entity) {
                case 'trees':
                    if ($method === 'DELETE' && $id) {
                        $tree = $this->arbor->model('trees')->get($id);
                        if (!$tree) throw new Wire404Exception();
                        if (!$this->arbor->canDeleteTree($tree)) {
                            $this->respond(['error' => 'Tree deletion requires arbor-admin permission'], 403);
                            exit;
                        }
                        $this->arbor->model('trees')->delete($id);
                        $this->respond(['deleted' => $id]); return;
                    }
                    break;
                case 'persons':
                    if ($sub === 'names' && $id) { $this->personNamesWrite($id, $method, $body); return; }
                    if ($method === 'POST' && !$id) { $this->createPerson($body); return; }
                    if ($method === 'PUT' && $id) { $this->updatePerson($id, $body); return; }
                    if ($method === 'DELETE' && $id) { $this->deletePerson($id); return; }
                    break;
                case 'photos':
                case 'events':
                case 'citizenships':
                case 'documents':
                case 'associations':
                    $this->genericWrite($entity, $method, $id, $body); return;
            }
        }
        throw new Wire404Exception();
    }

    /* ---------- GET ---------- */

    protected function getTrees(): void
    {
        $rows = $this->arbor->model('trees')->all();
        $out = [];
        foreach ($rows as $r) {
            if (!$this->canViewTree($r)) continue;
            $out[] = ['id'=>(int)$r['id'],'name'=>$r['name'],'description'=>$r['description'],'is_public'=>(bool)$r['is_public']];
        }
        $this->respond($out);
    }

    protected function getTree(int $id): void
    {
        $tree = $this->arbor->model('trees')->get($id);
        if (!$tree || !$this->canViewTree($tree)) throw new Wire404Exception();
        $this->respond([
            'id' => (int) $tree['id'],
            'name' => $tree['name'],
            'description' => $tree['description'],
            'is_public' => (bool) $tree['is_public'],
        ]);
    }

    protected function getTreeGraph(int $id): void
    {
        $tree = $this->arbor->model('trees')->get($id);
        if (!$tree || !$this->canViewTree($tree)) throw new Wire404Exception();
        $db = $this->wire('database');

        $persons = $this->arbor->model('persons')->findByTree($id, ['limit' => 5000]);
        $maskLiving = $this->hideLiving && !$this->arbor->canEditTree($tree);
        $nodes = [];
        foreach ($persons as $p) {
            $events = $this->arbor->model('events')->forPerson((int) $p['id']);
            if ($maskLiving && $this->arbor->isLiving($p, $events)) {
                $nodes[] = ['id' => (int) $p['id'], 'name' => 'Living', 'is_alive' => true];
                continue;
            }
            $birthYear = null;
            $deathYear = null;
            foreach ($events as $event) {
                if (($event['event_type'] ?? '') === 'birth' && !$birthYear) {
                    $birthYear = $this->eventYear($event);
                } elseif (($event['event_type'] ?? '') === 'death' && !$deathYear) {
                    $deathYear = $this->eventYear($event);
                }
            }
            $years = '';
            if ($birthYear || $deathYear) $years = ($birthYear ?: '?') . '-' . ($deathYear ?: '');
            $name = trim(($p['given'] ?? '') . ' ' . ($p['surname'] ?? ''));
            $nodes[] = [
                'id' => (int) $p['id'],
                'name' => $name ?: '#' . $p['id'],
                'sex' => $p['sex'],
                'is_alive' => (bool) $p['is_alive'],
                'birth_year' => $birthYear,
                'death_year' => $deathYear,
                'years' => $years,
            ];
        }

        $unions = $this->arbor->model('unions')->forTree($id);
        $edges = [];
        $edgeKeys = [];
        $addEdge = function (int $from, int $to, string $type, array $extra = []) use (&$edges, &$edgeKeys): void {
            if (!$from || !$to) return;
            $key = $type . ':' . min($from, $to) . ':' . max($from, $to);
            if (isset($edgeKeys[$key])) return;
            $edgeKeys[$key] = true;
            $edges[] = ['from' => $from, 'to' => $to, 'type' => $type] + $extra;
        };
        foreach ($unions as $u) {
            $children = $this->arbor->model('unions')->children((int) $u['id']);
            foreach ($children as $c) {
                if ($u['partner1_id']) $addEdge((int) $u['partner1_id'], (int) $c['person_id'], 'child');
                if ($u['partner2_id']) $addEdge((int) $u['partner2_id'], (int) $c['person_id'], 'child');
            }
            if ($u['partner1_id'] && $u['partner2_id']) {
                $addEdge((int) $u['partner1_id'], (int) $u['partner2_id'], 'spouse', ['union_id' => (int) $u['id']]);
            }
        }

        $this->respond(['nodes' => $nodes, 'edges' => $edges]);
    }

    protected function eventYear(array $event): ?string
    {
        $date = (string) ($event['event_date'] ?? '');
        if ($date !== '' && preg_match('/\d{4}/', $date, $m)) return $m[0];
        return null;
    }

    protected function getPerson(int $id, string $sub): void
    {
        $person = $this->arbor->model('persons')->get($id);
        if (!$person) throw new Wire404Exception();
        $tree = $this->arbor->model('trees')->get((int) $person['tree_id']);
        if (!$tree || !$this->canViewTree($tree)) throw new Wire404Exception();

        $events = $this->arbor->model('events')->forPerson($id);
        $maskLiving = $this->hideLiving && !$this->arbor->canEditTree($tree) && $this->arbor->isLiving($person, $events);

        switch ($sub) {
            case '':         $this->respond($this->personProfile($person, $events, $maskLiving)); return;
            case 'names':    $this->respond($maskLiving ? [] : $this->arbor->model('names')->forPerson($id)); return;
            case 'photos':   $this->respond($maskLiving ? [] : $this->serializePhotos($id)); return;
            case 'relatives':$this->respond($maskLiving ? [] : $this->arbor->model('persons')->relatives($id)); return;
            case 'events':   $this->respond($maskLiving ? [] : $this->serializeEvents($events)); return;
            case 'timeline': $this->respond($maskLiving ? [] : $this->buildTimeline($events)); return;
            case 'citizenships': $this->respond($maskLiving ? [] : $this->arbor->model('citizenships')->forPerson($id)); return;
            case 'documents':$this->respond($maskLiving ? [] : $this->serializeDocuments($id)); return;
            case 'associations': $this->respond($maskLiving ? [] : $this->arbor->model('associations')->forPerson($id)); return;
            case 'dna':      $this->respond($maskLiving ? [] : $this->arbor->model('dna')->kitsForPerson($id)); return;
        }
        throw new Wire404Exception();
    }

    protected function personProfile(array $person, array $events, bool $maskLiving): array
    {
        $arbor = $this->arbor;
        $id = (int) $person['id'];

        if ($maskLiving) {
            $primary = $arbor->model('names')->primary($id);
            $surname = $primary['surname'] ?? '';
            return ['id' => $id, 'name' => 'Living' . ($surname ? ' ' . $surname : ''), 'is_alive' => true];
        }

        $primary = $arbor->model('names')->primary($id);
        $birth = null; $death = null;
        foreach ($events as $e) {
            if ($e['event_type'] === 'birth' && !$birth) $birth = $e;
            if ($e['event_type'] === 'death' && !$death) $death = $e;
        }
        $profilePhoto = $arbor->model('photos')->profile($id);

        return [
            'id' => $id,
            'uid' => $person['uid'],
            'tree_id' => (int) $person['tree_id'],
            'sex' => $person['sex'],
            'is_alive' => (bool) $person['is_alive'],
            'ethnicity' => $person['ethnicity'],
            'religion' => $person['religion'],
            'is_cohen' => (bool) $person['is_cohen'],
            'is_levi' => (bool) $person['is_levi'],
            'primary_name' => $primary ? [
                'given' => $primary['given'], 'surname' => $primary['surname'],
                'patronymic' => $primary['patronymic'], 'given_hebrew' => $primary['given_hebrew'],
                'script' => $primary['script'],
            ] : null,
            'birth_date' => $birth['event_date'] ?? null,
            'birth_place' => $birth ? $this->placeName($birth) : null,
            'death_date' => $death['event_date'] ?? null,
            'death_place' => $death ? $this->placeName($death) : null,
            'profile_photo' => $profilePhoto ? $arbor->model('photos')->url($profilePhoto) : null,
            'citizenships' => $arbor->model('citizenships')->forPerson($id),
            'bio' => $person['bio'],
            'external_ids' => array_map(fn($x) => ['type' => $x['id_type'], 'id' => $x['external_id']], $arbor->model('persons')->externalIds($id)),
            'photos_count' => count($arbor->model('photos')->forPerson($id)),
            'events_count' => count($events),
            'documents_count' => count($arbor->model('documents')->forPerson($id)),
        ];
    }

    protected function buildTimeline(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            $kind = match ($e['event_type']) {
                'birth' => 'birth',
                'death' => 'death',
                default => 'event',
            };
            $item = [
                'type' => $kind,
                'date' => $e['event_date'] ?: ($e['event_date_phrase'] ?: null),
                'place' => $this->placeName($e),
            ];
            if ($kind === 'event') {
                $item['event_type'] = $e['event_type'];
                $item['title'] = $e['title'] ?: ucfirst($e['event_type']);
            }
            $out[] = $item;
        }
        return $out;
    }

    protected function placeName(array $event): ?string
    {
        if (!empty($event['event_place_id'])) {
            return $this->arbor->model('places')->fullPath((int) $event['event_place_id']);
        }
        return $event['event_place_str'] ?: null;
    }

    protected function serializeEvents(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            $out[] = [
                'id' => (int) $e['id'],
                'event_type' => $e['event_type'],
                'event_subtype' => $e['event_subtype'],
                'title' => $e['title'],
                'date' => $e['event_date'] ?: $e['event_date_phrase'],
                'date_cal' => $e['event_date_cal'],
                'date_hebrew' => $e['event_date_hebrew'],
                'place' => $this->placeName($e),
                'description' => $e['description'],
                'fields' => $this->arbor->model('events')->getFields((int) $e['id']),
            ];
        }
        return $out;
    }

    protected function serializePhotos(int $personId): array
    {
        $model = $this->arbor->model('photos');
        $out = [];
        foreach ($model->forPerson($personId) as $p) {
            $p['url'] = $model->url($p);
            $out[] = $p;
        }
        return $out;
    }

    protected function serializeDocuments(int $personId): array
    {
        return $this->arbor->model('documents')->forPerson($personId);
    }

    protected function getPlace(int $id, string $sub): void
    {
        $place = $this->arbor->model('places')->get($id);
        if (!$place) throw new Wire404Exception();
        $tree = $this->arbor->model('trees')->get((int) $place['tree_id']);
        if (!$tree || !$this->canViewTree($tree)) throw new Wire404Exception();
        switch ($sub) {
            case '':              $this->respond($place); return;
            case 'children':      $this->respond($this->arbor->model('places')->forTree((int) $place['tree_id'], $id)); return;
            case 'names':         $this->respond($this->arbor->model('places')->names($id)); return;
            case 'jurisdictions': $this->respond($this->arbor->model('places')->jurisdictions($id)); return;
        }
        throw new Wire404Exception();
    }

    /* ---------- writes ---------- */

    protected function createPerson(array $body): void
    {
        $this->requireCanEditTreeId((int) ($body['tree_id'] ?? 0));
        $id = $this->arbor->model('persons')->save($body);
        $this->respond(['id' => $id], 201);
    }

    protected function updatePerson(int $id, array $body): void
    {
        $current = $this->arbor->model('persons')->get($id);
        if (!$current) throw new Wire404Exception();
        $this->requireCanEditTreeId((int) $current['tree_id']);
        if (isset($body['tree_id']) && (int) $body['tree_id'] !== (int) $current['tree_id']) {
            $this->respond(['error' => 'Cannot move a person between trees'], 400);
            exit;
        }
        $merged = array_merge($current, $body);
        $this->arbor->model('persons')->save($merged, $id);
        $this->respond(['id' => $id]);
    }

    protected function deletePerson(int $id): void
    {
        $treeId = $this->arbor->treeIdForRecord('persons', $id);
        if (!$treeId) throw new Wire404Exception();
        $this->requireCanEditTreeId($treeId);
        $this->arbor->model('persons')->delete($id);
        $this->respond(['deleted' => $id]);
    }

    protected function personNamesWrite(int $personId, string $method, array $body): void
    {
        $model = $this->arbor->model('names');
        if ($method === 'POST') {
            $this->requireCanEditRecord('persons', $personId);
            $treeId = $this->arbor->treeIdForRecord('persons', $personId);
            if (!$treeId) throw new Wire404Exception();
            $body['person_id'] = $personId;
            $id = $model->save($body);
            $this->respond(['id' => $id], 201);
        } elseif ($method === 'PUT' && !empty($body['id'])) {
            $this->requireCanEditRecord('persons', $personId);
            $this->requireCanEditRecord('names', (int) $body['id']);
            $personTreeId = $this->arbor->treeIdForRecord('persons', $personId);
            $nameTreeId = $this->arbor->treeIdForRecord('names', (int) $body['id']);
            if (!$personTreeId || $personTreeId !== $nameTreeId) {
                $this->respond(['error' => 'Name does not belong to this person tree'], 400);
                exit;
            }
            $body['person_id'] = $personId;
            $model->save($body, (int) $body['id']);
            $this->respond(['id' => (int) $body['id']]);
        } elseif ($method === 'DELETE' && !empty($body['id'])) {
            $this->requireCanEditRecord('persons', $personId);
            $this->requireCanEditRecord('names', (int) $body['id']);
            $personTreeId = $this->arbor->treeIdForRecord('persons', $personId);
            $nameTreeId = $this->arbor->treeIdForRecord('names', (int) $body['id']);
            if (!$personTreeId || $personTreeId !== $nameTreeId) {
                $this->respond(['error' => 'Name does not belong to this person tree'], 400);
                exit;
            }
            $model->delete((int) $body['id']);
            $this->respond(['deleted' => (int) $body['id']]);
        } else {
            throw new Wire404Exception();
        }
    }

    protected function genericWrite(string $entity, string $method, int $id, array $body): void
    {
        $modelMap = ['photos' => 'photos', 'events' => 'events', 'citizenships' => 'citizenships',
                     'documents' => 'documents', 'associations' => 'associations'];
        if (!isset($modelMap[$entity])) throw new Wire404Exception();
        $model = $this->arbor->model($modelMap[$entity]);

        if ($method === 'POST') {
            $treeId = $this->treeIdFromWritePayload($entity, $body);
            $this->requireCanEditTreeId($treeId);
            $this->assertPayloadBelongsToTree($body, $treeId);
            $newId = $model->save($body);
            $this->respond(['id' => $newId], 201);
        } elseif ($method === 'PUT' && $id) {
            $current = $model->get($id);
            if (!$current) throw new Wire404Exception();
            $this->requireCanEditRecord($entity, $id);
            $currentTreeId = $this->arbor->treeIdForRecord($entity, $id);
            if (!$currentTreeId) throw new Wire404Exception();
            $this->assertPayloadBelongsToTree($body, $currentTreeId);
            $model->save(array_merge($current, $body), $id);
            $this->respond(['id' => $id]);
        } elseif ($method === 'DELETE' && $id) {
            $this->requireCanEditRecord($entity, $id);
            $model->delete($id);
            $this->respond(['deleted' => $id]);
        } else {
            throw new Wire404Exception();
        }
    }

    /* ---------- helpers ---------- */

    protected function canViewTree(array $tree): bool
    {
        if ($this->requireAuthReads) {
            $user = $this->wire('user');
            if (!$user->isLoggedin()) return false;
            return $this->arbor->canViewTree($tree);
        }
        return $this->arbor->canViewTree($tree);
    }

    protected function requireCanEditRecord(string $entity, int $id): void
    {
        $treeId = $this->arbor->treeIdForRecord($entity, $id);
        if (!$treeId) throw new Wire404Exception();
        $this->requireCanEditTreeId($treeId);
    }

    protected function requireCanEditTreeId(int $treeId): void
    {
        if (!$treeId) {
            $this->respond(['error' => 'Missing tree_id'], 400);
            exit;
        }
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree) throw new Wire404Exception();
        if (!$this->arbor->canEditTree($tree)) {
            $this->respond(['error' => 'Forbidden for this tree'], 403);
            exit;
        }
    }

    protected function treeIdFromWritePayload(string $entity, array $body): int
    {
        if (!empty($body['tree_id'])) return (int) $body['tree_id'];
        if (!empty($body['person_id'])) {
            $treeId = $this->arbor->treeIdForRecord('persons', (int) $body['person_id']);
            return $treeId ?: 0;
        }
        if (!empty($body['event_id'])) {
            $treeId = $this->arbor->treeIdForRecord('events', (int) $body['event_id']);
            return $treeId ?: 0;
        }
        return 0;
    }

    protected function assertPayloadBelongsToTree(array $body, int $treeId): void
    {
        if (!empty($body['tree_id']) && (int) $body['tree_id'] !== $treeId) {
            $this->respond(['error' => 'Cannot move records between trees'], 400);
            exit;
        }
        $links = [
            'person_id' => 'persons',
            'related_id' => 'persons',
            'event_id' => 'events',
            'union_id' => 'unions',
            'source_id' => 'sources',
            'repo_id' => 'repositories',
            'citation_id' => 'citations',
            'photo_id' => 'photos',
            'place_id' => 'places',
            'event_place_id' => 'places',
            'doc_place_id' => 'places',
        ];
        foreach ($links as $field => $entity) {
            if (empty($body[$field])) continue;
            $linkedTreeId = $this->arbor->treeIdForRecord($entity, (int) $body[$field]);
            if (!$linkedTreeId || $linkedTreeId !== $treeId) {
                $this->respond(['error' => "$field does not belong to this tree"], 400);
                exit;
            }
        }
    }

    protected function requireAuth(string $method): void
    {
        $user = $this->wire('user');

        if ($user->isLoggedin()) {
            if ($method !== 'GET') {
                $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_REQUEST['csrf'] ?? '');
                $csrf = $this->wire('session')->CSRF;
                $validToken = $csrf->hasValidToken();
                if (!$validToken && $token !== '' && method_exists($csrf, 'getTokenValue')) {
                    $validToken = hash_equals((string) $csrf->getTokenValue(), (string) $token);
                }
                if (!$validToken) {
                    $this->respond(['error' => 'Invalid CSRF token'], 403);
                    exit;
                }
            }
            if (!$user->hasPermission('arbor-edit')) {
                $this->respond(['error' => 'Forbidden'], 403);
                exit;
            }
            return;
        }

        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/', $auth, $m)) {
            $tokenUser = $this->wire('users')->get('arbor_api_token=' . $this->wire('sanitizer')->selectorValue($m[1]));
            if ($tokenUser->id && $tokenUser->hasPermission('arbor-edit')) {
                $this->wire('users')->setCurrentUser($tokenUser);
                return;
            }
        }
        $this->respond(['error' => 'Unauthorized'], 401);
        exit;
    }

    protected function parseBody(): array
    {
        $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ctype, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        }
        return $_POST;
    }

    protected function respond($data, int $status = 200): void
    {
        $data = $this->outputBefore($data);
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            $origin = trim((string) $this->corsOrigin);
            if ($origin !== '') header('Access-Control-Allow-Origin: ' . $origin);
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Hookable: filter API response payload right before JSON encoding.
     * Add a hook with `$wire->addHookAfter('ArborApi::outputBefore', ...)`.
     */
    public function ___outputBefore($data) { return $data; }

    /* ============== ConfigurableModule ============== */

    public static function getModuleConfigInputfields(array $data): InputfieldWrapper
    {
        $defaults = self::getDefaultConfig();
        $data = array_merge($defaults, $data);
        $modules = wire('modules');
        $form = new InputfieldWrapper();

        // ===== Master toggle =====
        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'enabled';
        $f->label = 'API enabled';
        $f->label2 = 'Yes, expose Arbor data through the REST API';
        $f->description = 'Master switch. When off, no endpoints respond — every URL under the API base path returns the standard PW 404. Useful while seeding data or for fully private installations.';
        $f->notes = 'Toggling off does NOT delete any data; it only unbinds the route hook.';
        $f->checked = (bool) $data['enabled'];
        $form->add($f);

        // ===== Routing =====
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = 'Routing';
        $fs->icon = 'sitemap';
        $fs->showIf = 'enabled=1';
        $form->add($fs);

        $f = $modules->get('InputfieldText');
        $f->name = 'apiBase';
        $f->label = 'API base path';
        $f->description = 'URL prefix under which Arbor matches incoming requests. Must begin and end with a slash.';
        $f->notes = 'Default: /api/arbor/  ·  Examples: /api/, /genealogy/api/';
        $f->value = $data['apiBase'];
        $f->required = true;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'corsOrigin';
        $f->label = 'CORS — Access-Control-Allow-Origin';
        $f->description = 'Value sent in the Access-Control-Allow-Origin response header. Set to your frontend origin (e.g. https://example.com), use * to allow any, or leave blank to omit the header.';
        $f->notes = 'For credentialed requests, a wildcard is not allowed by the browser — use the exact origin.';
        $f->value = $data['corsOrigin'];
        $fs->add($f);

        // ===== Privacy =====
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = 'Privacy';
        $fs->icon = 'lock';
        $fs->showIf = 'enabled=1';
        $form->add($fs);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'hideLiving';
        $f->label = 'Mask living persons on public API';
        $f->label2 = 'Replace living-person data with a stub on public reads';
        $f->description = 'Public API responses for persons flagged as living return only id + surname + a "Living" placeholder. Field-level data, photos, events and citizenships are omitted.';
        $f->notes = 'Authenticated tree owners always see full data.';
        $f->checked = (bool) $data['hideLiving'];
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'livingThreshold';
        $f->label = 'Assume dead beyond this age (years)';
        $f->description = 'If a person has is_alive = 1 but their birth year is older than this threshold, Arbor treats them as deceased for masking purposes.';
        $f->notes = 'Common values: 100 (conservative), 110 (default), 120 (paranoid).';
        $f->value = $data['livingThreshold'];
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'requireAuthReads';
        $f->label = 'Require authentication for all reads';
        $f->label2 = 'Even public trees require a logged-in user or Bearer token';
        $f->description = 'Hardens the API by disabling the public-tree exception on read endpoints. Useful for installations that only serve authenticated frontends.';
        $f->checked = (bool) $data['requireAuthReads'];
        $fs->add($f);

        return $form;
    }

    /**
     * Machine- and human-readable map of every Arbor API endpoint, grouped by
     * resource. Returned at the API root and used by the admin dashboard.
     */
    public function endpointCatalog(): array
    {
        $base = '/' . trim($this->apiBase, '/') . '/';
        return [
            'service' => 'Arbor REST API',
            'version'  => 100,
            'base'    => $base,
            'auth'    => 'Public reads on public trees; cookie session + CSRF or Bearer token for writes.',
            'groups'  => [
                [
                    'name'  => 'Trees',
                    'endpoints' => [
                        ['method' => 'GET',    'path' => $base . 'trees/',                'desc' => 'List visible trees'],
                        ['method' => 'GET',    'path' => $base . 'trees/{id}/',           'desc' => 'Single tree metadata'],
                        ['method' => 'GET',    'path' => $base . 'trees/{id}/graph/',     'desc' => 'D3 graph data (nodes + edges)'],
                        ['method' => 'DELETE', 'path' => $base . 'trees/{id}/',           'desc' => 'Delete tree (requires arbor-admin)'],
                    ],
                ],
                [
                    'name'  => 'Persons',
                    'endpoints' => [
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/',              'desc' => 'Person profile card'],
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/names/',        'desc' => 'All names (BIRTH, AKA, …)'],
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/photos/',       'desc' => 'Photo gallery with URLs'],
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/relatives/',    'desc' => 'Parents / children / siblings / spouses'],
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/events/',       'desc' => 'Life events with type-specific fields'],
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/timeline/',     'desc' => 'Computed chronological timeline'],
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/citizenships/', 'desc' => 'Citizenship history'],
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/documents/',    'desc' => 'Archival documents'],
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/associations/', 'desc' => 'FAN club roles (witness, sandek, …)'],
                        ['method' => 'GET',    'path' => $base . 'persons/{id}/dna/',          'desc' => 'DNA kits'],
                        ['method' => 'POST',   'path' => $base . 'persons/',                   'desc' => 'Create person'],
                        ['method' => 'PUT',    'path' => $base . 'persons/{id}/',              'desc' => 'Update person'],
                        ['method' => 'DELETE', 'path' => $base . 'persons/{id}/',              'desc' => 'Delete person'],
                        ['method' => 'POST',   'path' => $base . 'persons/{id}/names/',        'desc' => 'Add a name row'],
                        ['method' => 'PUT',    'path' => $base . 'persons/{id}/names/',        'desc' => 'Update a name row (body.id required)'],
                        ['method' => 'DELETE', 'path' => $base . 'persons/{id}/names/',        'desc' => 'Delete a name row (body.id required)'],
                    ],
                ],
                [
                    'name'  => 'Places',
                    'endpoints' => [
                        ['method' => 'GET', 'path' => $base . 'places/{id}/',               'desc' => 'Single place record'],
                        ['method' => 'GET', 'path' => $base . 'places/{id}/children/',      'desc' => 'Direct child places'],
                        ['method' => 'GET', 'path' => $base . 'places/{id}/names/',         'desc' => 'Historical / multi-script names'],
                        ['method' => 'GET', 'path' => $base . 'places/{id}/jurisdictions/', 'desc' => 'Date-bounded jurisdiction history'],
                    ],
                ],
                [
                    'name'  => 'Sub-records (writes only)',
                    'endpoints' => [
                        ['method' => 'POST',   'path' => $base . 'events/',             'desc' => 'Create event'],
                        ['method' => 'PUT',    'path' => $base . 'events/{id}/',        'desc' => 'Update event'],
                        ['method' => 'DELETE', 'path' => $base . 'events/{id}/',        'desc' => 'Delete event'],
                        ['method' => 'POST',   'path' => $base . 'photos/',             'desc' => 'Create photo metadata row'],
                        ['method' => 'PUT',    'path' => $base . 'photos/{id}/',        'desc' => 'Update photo metadata'],
                        ['method' => 'DELETE', 'path' => $base . 'photos/{id}/',        'desc' => 'Delete photo + file'],
                        ['method' => 'POST',   'path' => $base . 'citizenships/',       'desc' => 'Add citizenship'],
                        ['method' => 'PUT',    'path' => $base . 'citizenships/{id}/',  'desc' => 'Update citizenship'],
                        ['method' => 'DELETE', 'path' => $base . 'citizenships/{id}/',  'desc' => 'Delete citizenship'],
                        ['method' => 'POST',   'path' => $base . 'documents/',          'desc' => 'Add document'],
                        ['method' => 'PUT',    'path' => $base . 'documents/{id}/',     'desc' => 'Update document'],
                        ['method' => 'DELETE', 'path' => $base . 'documents/{id}/',     'desc' => 'Delete document'],
                        ['method' => 'POST',   'path' => $base . 'associations/',       'desc' => 'Add association'],
                        ['method' => 'PUT',    'path' => $base . 'associations/{id}/',  'desc' => 'Update association'],
                        ['method' => 'DELETE', 'path' => $base . 'associations/{id}/',  'desc' => 'Delete association'],
                    ],
                ],
            ],
        ];
    }
}
