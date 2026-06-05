<?php namespace ProcessWire;

/**
 * GEDCOM 5.5.1 and 7.0 import/export for Arbor.
 *
 * Import accepts both versions and a handful of proprietary extension tags
 * (Family Historian _FREL/_MREL, MacFamilyTree, FTM custom EVENs). Export
 * produces lossy GEDCOM 5.5.1 (splitting shared events to per-person records,
 * Hebrew names as AKA) or full-fidelity 7.0 with ASSO/ROLE, EXID, UID, _LOC,
 * SDATE, TRAN substructures.
 */
class ArborGedcom extends Wire
{
    protected Arbor $arbor;

    protected array $eventTagMap = [
        'BIRT' => 'birth', 'DEAT' => 'death', 'BURI' => 'burial', 'CREM' => 'cremation',
        'BAPM' => 'baptism', 'BARM' => 'bar_mitzvah', 'BASM' => 'bat_mitzvah',
        'CHR'  => 'christening', 'CHRA' => 'christening_adult', 'CONF' => 'confirmation',
        'EMIG' => 'emigration', 'IMMI' => 'immigration', 'NATU' => 'naturalization',
        'CENS' => 'census', 'ADOP' => 'adoption', 'GRAD' => 'graduation',
        'RETI' => 'retirement', 'WILL' => 'will', 'PROB' => 'probate', 'ORDN' => 'ordination',
        'BLES' => 'blessing', 'FCOM' => 'first_communion',
        // attributes
        'OCCU' => 'occupation', 'RELI' => 'religion', 'RESI' => 'residence',
        'EDUC' => 'education', 'NATI' => 'nationality', 'CAST' => 'caste',
        'TITL' => 'title', 'DSCR' => 'description', 'IDNO' => 'id_number',
        'NCHI' => 'children_count', 'NMR' => 'marriage_count', 'PROP' => 'property',
        'SSN' => 'ssn', 'FACT' => 'fact', 'MILI' => 'military_service',
        // family events
        'MARR' => 'marriage', 'DIV'  => 'divorce', 'DIVF' => 'divorce_filed',
        'ENGA' => 'engagement', 'MARB' => 'marriage_bann', 'MARC' => 'marriage_contract',
        'MARL' => 'marriage_license', 'MARS' => 'marriage_settlement', 'ANUL' => 'annulment',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    /* ============== IMPORT ============== */

    public function import(string $path, int $treeId): array
    {
        $stats = ['persons'=>0,'unions'=>0,'sources'=>0,'places'=>0,'errors'=>0];
        if (!is_file($path)) return $stats;
        $arbor = $this->arbor;
        $arbor->importBefore($path, ['tree_id' => $treeId]);

        $records = $this->parseRecords(file_get_contents($path));

        $personMap = []; $unionMap = []; $sourceMap = []; $repoMap = []; $placeMap = [];

        foreach ($records as $rec) {
            if ($rec['tag'] === 'REPO') {
                $id = $arbor->model('repositories')->save([
                    'tree_id' => $treeId,
                    'name' => $this->subValue($rec, 'NAME') ?: ($rec['xref'] ?? ''),
                    'address' => $this->subValue($rec, 'ADDR'),
                    'website' => $this->subValue($rec, 'WWW'),
                ]);
                $repoMap[$rec['xref']] = $id;
            }
        }

        foreach ($records as $rec) {
            if ($rec['tag'] === 'SOUR') {
                $repoRef = $this->subValue($rec, 'REPO');
                $sid = $arbor->model('sources')->save([
                    'tree_id' => $treeId,
                    'repo_id' => $repoRef && isset($repoMap[$repoRef]) ? $repoMap[$repoRef] : null,
                    'title' => $this->subValue($rec, 'TITL'),
                    'author' => $this->subValue($rec, 'AUTH'),
                    'publisher' => $this->subValue($rec, 'PUBL'),
                    'pub_date' => $this->subValue($rec, 'PUBL.DATE'),
                    'abstract' => $this->subValue($rec, 'ABBR'),
                    'full_text' => $this->subValue($rec, 'TEXT'),
                ]);
                $sourceMap[$rec['xref']] = $sid;
                $stats['sources']++;
            }
        }

        foreach ($records as $rec) {
            if ($rec['tag'] !== 'INDI') continue;
            $personId = $this->importPerson($rec, $treeId);
            $personMap[$rec['xref']] = $personId;
            $stats['persons']++;
        }

        foreach ($records as $rec) {
            if ($rec['tag'] !== 'FAM') continue;
            $u = ['tree_id' => $treeId, 'gedcom_id' => $rec['xref']];
            $u['partner1_id'] = $this->lookupPerson($personMap, $this->subValue($rec, 'HUSB'));
            $u['partner2_id'] = $this->lookupPerson($personMap, $this->subValue($rec, 'WIFE'));
            $marr = $this->findSub($rec, 'MARR');
            if ($marr) {
                $u['married_date'] = $this->parseDate($this->subValue($marr, 'DATE'));
                $u['union_type'] = 'married_civil';
            }
            $divDate = $this->subValue($rec, 'DIV.DATE');
            if ($divDate) { $u['divorced'] = 1; $u['divorced_date'] = $this->parseDate($divDate); }
            $unionId = $arbor->model('unions')->save($u);
            $unionMap[$rec['xref']] = $unionId;
            $stats['unions']++;

            foreach ($this->findAll($rec, 'CHIL') as $ch) {
                $childId = $this->lookupPerson($personMap, $ch['value']);
                if ($childId) $arbor->model('unions')->addChild($unionId, $childId);
            }

            foreach ($rec['children'] as $sub) {
                if (isset($this->eventTagMap[$sub['tag']]) && !in_array($sub['tag'], ['HUSB','WIFE','CHIL'], true)) {
                    $this->importEvent($sub, $treeId, null, $unionId);
                }
            }
        }

        foreach ($records as $rec) {
            if ($rec['tag'] !== 'INDI') continue;
            $personId = $personMap[$rec['xref']] ?? null;
            if (!$personId) continue;
            foreach ($this->findAll($rec, 'ASSO') as $asso) {
                $relRef = $asso['value'];
                $relId = $this->lookupPerson($personMap, $relRef);
                $role = $this->subValue($asso, 'ROLE') ?: 'OTHER';
                $arbor->model('associations')->save([
                    'person_id' => $personId, 'related_id' => $relId,
                    'role' => $this->mapRole($role),
                    'role_phrase' => $role,
                ]);
            }
        }

        $arbor->importAfter($stats);
        return $stats;
    }

    protected function lookupPerson(array $map, ?string $xref): ?int
    {
        if (!$xref) return null;
        $xref = trim($xref, '@');
        return $map['@' . $xref . '@'] ?? ($map[$xref] ?? null);
    }

    protected function importPerson(array $rec, int $treeId): int
    {
        $arbor = $this->arbor;
        $sex = strtoupper($this->subValue($rec, 'SEX') ?: 'U');
        if (!in_array($sex, ['M','F','X','U'], true)) $sex = 'U';

        $pid = $arbor->model('persons')->save([
            'tree_id' => $treeId,
            'gedcom_id' => $rec['xref'],
            'uid' => $this->subValue($rec, 'UID') ?: ArborPerson::uuid4(),
            'sex' => $sex,
            'religion' => $this->subValue($rec, 'RELI'),
            'ethnicity' => $this->subValue($rec, 'NATI'),
            'resn' => strtolower($this->subValue($rec, 'RESN') ?: 'none'),
            'refn' => $this->subValue($rec, 'REFN'),
            'is_alive' => $this->findSub($rec, 'DEAT') ? 0 : 1,
        ]);

        foreach ($this->findAll($rec, 'NAME') as $nameNode) {
            $type = strtoupper($this->subValue($nameNode, 'TYPE') ?: 'BIRTH');
            $allowed = ['BIRTH','AKA','IMMIGRANT','MAIDEN','MARRIED','PROFESSIONAL','OTHER'];
            if (!in_array($type, $allowed, true)) $type = 'OTHER';
            [$given, $surname] = $this->splitName($nameNode['value']);
            $arbor->model('names')->save([
                'person_id' => $pid,
                'name_type' => $type,
                'prefix' => $this->subValue($nameNode, 'NPFX'),
                'given' => $this->subValue($nameNode, 'GIVN') ?: $given,
                'nickname' => $this->subValue($nameNode, 'NICK'),
                'surname_pfx' => $this->subValue($nameNode, 'SPFX'),
                'surname' => $this->subValue($nameNode, 'SURN') ?: $surname,
                'suffix' => $this->subValue($nameNode, 'NSFX'),
            ]);
        }

        foreach ($this->findAll($rec, 'EXID') as $ex) {
            $arbor->model('persons')->saveExternalId($pid, $this->subValue($ex, 'TYPE') ?: '', $ex['value']);
        }

        foreach ($rec['children'] as $sub) {
            if (isset($this->eventTagMap[$sub['tag']])) {
                $this->importEvent($sub, $treeId, $pid, null);
            }
        }

        return $pid;
    }

    protected function importEvent(array $node, int $treeId, ?int $personId, ?int $unionId): void
    {
        $type = $this->eventTagMap[$node['tag']] ?? 'other';
        $place = $this->subValue($node, 'PLAC');
        $this->arbor->model('events')->save([
            'tree_id' => $treeId, 'person_id' => $personId, 'union_id' => $unionId,
            'event_type' => $type,
            'event_subtype' => $node['tag'] === 'EVEN' ? $this->subValue($node, 'TYPE') : '',
            'title' => $this->subValue($node, 'TYPE') ?: ucfirst($type),
            'event_date' => $this->parseDate($this->subValue($node, 'DATE')),
            'event_date_phrase' => $this->parseDatePhrase($this->subValue($node, 'DATE')),
            'event_date_approx' => $this->isApproxDate($this->subValue($node, 'DATE')) ? 1 : 0,
            'event_place_str' => $place,
            'cause' => $this->subValue($node, 'CAUS'),
            'agency' => $this->subValue($node, 'AGNC'),
            'age_at_event' => $this->subValue($node, 'AGE'),
            'description' => $node['value'] ?: $this->subValue($node, 'NOTE'),
        ]);
    }

    protected function mapRole(string $r): string
    {
        $allowed = ['CHIL','CLERGY','FATH','FRIEND','GODP','HUSB','MOTH','MULTIPLE','NGHBR',
                    'OFFICIATOR','PARENT','SPOU','WIFE','WITN','SANDEK','KVATER','SHADCHAN',
                    'EMPLOYER','EMPLOYEE','TEACHER','STUDENT','EXECUTOR','DOCTOR','LAWYER',
                    'GUARDIAN','MASTER','APPRENTICE','PARTNER','LODGER','OTHER'];
        $u = strtoupper($r);
        return in_array($u, $allowed, true) ? $u : 'OTHER';
    }

    protected function splitName(string $raw): array
    {
        if (preg_match('#^(.*?)/(.*?)/(.*)$#', $raw, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [trim($raw), ''];
    }

    protected function parseDate(?string $raw): ?string
    {
        if (!$raw) return null;
        $raw = trim($raw);
        $raw = preg_replace('/^(ABT|EST|CAL|BEF|AFT|FROM|TO|BET|AND)\s+/i', '', $raw);
        $raw = preg_replace('/\s+(AND|TO)\s+.*$/i', '', $raw);
        $months = ['JAN'=>'01','FEB'=>'02','MAR'=>'03','APR'=>'04','MAY'=>'05','JUN'=>'06',
                   'JUL'=>'07','AUG'=>'08','SEP'=>'09','OCT'=>'10','NOV'=>'11','DEC'=>'12'];
        if (preg_match('/^(\d{1,2})\s+([A-Z]{3})\s+(\d{4})$/i', $raw, $m)) {
            $mn = $months[strtoupper($m[2])] ?? '01';
            return sprintf('%04d-%s-%02d', $m[3], $mn, $m[1]);
        }
        if (preg_match('/^([A-Z]{3})\s+(\d{4})$/i', $raw, $m)) {
            $mn = $months[strtoupper($m[1])] ?? '01';
            return sprintf('%s-%s-01', $m[2], $mn);
        }
        if (preg_match('/^(\d{4})$/', $raw, $m)) return sprintf('%s-01-01', $m[1]);
        return null;
    }

    protected function parseDatePhrase(?string $raw): string
    {
        if (!$raw) return '';
        return preg_match('/^(ABT|EST|CAL|BEF|AFT|FROM|BET)/i', $raw) ? $raw : '';
    }

    protected function isApproxDate(?string $raw): bool
    {
        return $raw && preg_match('/^(ABT|EST|CAL|BEF|AFT)/i', $raw);
    }

    /* ============== EXPORT ============== */

    public function export(int $treeId, string $version = '7.0'): string
    {
        return $version === '7.0' ? $this->exportV7($treeId) : $this->exportV551($treeId);
    }

    public function exportV551(int $treeId): string
    {
        $arbor = $this->arbor;
        $tree = $arbor->model('trees')->get($treeId);
        $out = [];
        $out[] = '0 HEAD';
        $out[] = '1 SOUR Arbor';
        $out[] = '2 VERS 2.0';
        $out[] = '2 NAME Arbor — ProcessWire Genealogy Module';
        $out[] = '1 GEDC';
        $out[] = '2 VERS 5.5.1';
        $out[] = '2 FORM LINEAGE-LINKED';
        $out[] = '1 CHAR UTF-8';

        $persons = $arbor->model('persons')->findByTree($treeId, ['limit' => 100000]);
        foreach ($persons as $p) {
            $out = array_merge($out, $this->exportPersonV551($p));
        }
        foreach ($arbor->model('unions')->forTree($treeId) as $u) {
            $out = array_merge($out, $this->exportUnionV551($u));
        }
        foreach ($arbor->model('sources')->forTree($treeId) as $s) {
            $xref = '@S' . $s['id'] . '@';
            $out[] = "0 $xref SOUR";
            if ($s['title']) $out[] = '1 TITL ' . $this->safe($s['title']);
            if ($s['author']) $out[] = '1 AUTH ' . $this->safe($s['author']);
            if ($s['publisher']) $out[] = '1 PUBL ' . $this->safe($s['publisher']);
        }
        foreach ($arbor->model('repositories')->forTree($treeId) as $r) {
            $xref = '@R' . $r['id'] . '@';
            $out[] = "0 $xref REPO";
            $out[] = '1 NAME ' . $this->safe($r['name']);
            if ($r['address']) $out[] = '1 ADDR ' . $this->safe($r['address']);
        }
        $out[] = '0 TRLR';
        return implode("\n", $out) . "\n";
    }

    protected function exportPersonV551(array $p): array
    {
        $arbor = $this->arbor;
        $xref = '@I' . $p['id'] . '@';
        $out = ["0 $xref INDI"];

        foreach ($arbor->model('names')->forPerson((int) $p['id']) as $n) {
            $name = ($n['given'] ? $n['given'] : '') . ' /' . $n['surname'] . '/';
            $out[] = '1 NAME ' . $this->safe($name);
            $out[] = '2 TYPE ' . $n['name_type'];
            if ($n['given']) $out[] = '2 GIVN ' . $this->safe($n['given']);
            if ($n['surname']) $out[] = '2 SURN ' . $this->safe($n['surname']);
            if ($n['nickname']) $out[] = '2 NICK ' . $this->safe($n['nickname']);
            if ($n['given_hebrew']) {
                $out[] = '1 NAME ' . $this->safe($n['given_hebrew'] . ' //');
                $out[] = '2 TYPE AKA';
            }
        }
        if ($p['sex']) $out[] = '1 SEX ' . $p['sex'];
        if ($p['religion']) $out[] = '1 RELI ' . $this->safe($p['religion']);
        if ($p['ethnicity']) $out[] = '1 NATI ' . $this->safe($p['ethnicity']);
        if ($p['refn']) $out[] = '1 REFN ' . $this->safe($p['refn']);

        foreach ($arbor->model('events')->forPerson((int) $p['id']) as $e) {
            $tag = array_search($e['event_type'], $this->eventTagMap, true);
            if (!$tag) { $tag = 'EVEN'; }
            $out[] = '1 ' . $tag . ($tag === 'EVEN' ? ' ' . $this->safe($e['event_subtype'] ?: $e['title']) : '');
            if ($e['event_date']) $out[] = '2 DATE ' . $this->formatDate($e['event_date'], (bool) $e['event_date_approx']);
            elseif ($e['event_date_phrase']) $out[] = '2 DATE ' . $this->safe($e['event_date_phrase']);
            $place = $e['event_place_str'] ?: ($e['event_place_id'] ? $arbor->model('places')->fullPath((int) $e['event_place_id']) : '');
            if ($place) $out[] = '2 PLAC ' . $this->safe($place);
            if ($e['cause']) $out[] = '2 CAUS ' . $this->safe($e['cause']);
            if ($e['age_at_event']) $out[] = '2 AGE ' . $this->safe($e['age_at_event']);
        }
        if ($p['bio']) $out[] = '1 NOTE ' . $this->safe($p['bio']);
        return $out;
    }

    protected function exportUnionV551(array $u): array
    {
        $xref = '@F' . $u['id'] . '@';
        $out = ["0 $xref FAM"];
        if ($u['partner1_id']) $out[] = '1 HUSB @I' . $u['partner1_id'] . '@';
        if ($u['partner2_id']) $out[] = '1 WIFE @I' . $u['partner2_id'] . '@';
        foreach ($this->arbor->model('unions')->children((int) $u['id']) as $c) {
            $out[] = '1 CHIL @I' . $c['person_id'] . '@';
        }
        if ($u['married_date']) {
            $out[] = '1 MARR';
            $out[] = '2 DATE ' . $this->formatDate($u['married_date'], (bool) $u['married_date_approx']);
        }
        if ($u['divorced']) {
            $out[] = '1 DIV Y';
            if ($u['divorced_date']) $out[] = '2 DATE ' . $this->formatDate($u['divorced_date']);
        }
        return $out;
    }

    public function exportV7(int $treeId): string
    {
        $arbor = $this->arbor;
        $out = [];
        $out[] = '0 HEAD';
        $out[] = '1 GEDC';
        $out[] = '2 VERS 7.0';
        $out[] = '1 SOUR Arbor';
        $out[] = '2 VERS 2.0';
        $out[] = '1 SUBM @SUB1@';
        $out[] = '0 @SUB1@ SUBM';
        $out[] = '1 NAME Arbor';

        $persons = $arbor->model('persons')->findByTree($treeId, ['limit' => 100000]);
        foreach ($persons as $p) $out = array_merge($out, $this->exportPersonV7($p));
        foreach ($arbor->model('unions')->forTree($treeId) as $u) $out = array_merge($out, $this->exportUnionV7($u));
        foreach ($arbor->model('places')->forTree($treeId, null) as $pl) $out = array_merge($out, $this->exportPlaceV7($pl, $treeId));
        foreach ($arbor->model('sources')->forTree($treeId) as $s) {
            $out[] = '0 @S' . $s['id'] . '@ SOUR';
            if ($s['title']) $out[] = '1 TITL ' . $this->safe($s['title']);
            if ($s['author']) $out[] = '1 AUTH ' . $this->safe($s['author']);
            if ($s['publisher']) $out[] = '1 PUBL ' . $this->safe($s['publisher']);
            if ($s['fond']) {
                $out[] = '1 DATA';
                $out[] = '2 EVEN';
                $out[] = '3 NOTE ф. ' . $this->safe($s['fond']) . ', оп. ' . $this->safe($s['opis']) . ', д. ' . $this->safe($s['delo']);
            }
        }
        foreach ($arbor->model('repositories')->forTree($treeId) as $r) {
            $out[] = '0 @R' . $r['id'] . '@ REPO';
            $out[] = '1 NAME ' . $this->safe($r['name']);
            if ($r['address']) $out[] = '1 ADDR ' . $this->safe($r['address']);
        }
        $out[] = '0 TRLR';
        return implode("\n", $out) . "\n";
    }

    protected function exportPersonV7(array $p): array
    {
        $arbor = $this->arbor;
        $xref = '@I' . $p['id'] . '@';
        $out = ["0 $xref INDI"];
        if ($p['uid']) $out[] = '1 UID ' . $p['uid'];

        foreach ($arbor->model('names')->forPerson((int) $p['id']) as $n) {
            $name = ($n['given'] ?: '') . ' /' . $n['surname'] . '/';
            $out[] = '1 NAME ' . $this->safe($name);
            $out[] = '2 TYPE ' . $n['name_type'];
            if ($n['given']) $out[] = '2 GIVN ' . $this->safe($n['given']);
            if ($n['surname']) $out[] = '2 SURN ' . $this->safe($n['surname']);
            if ($n['nickname']) $out[] = '2 NICK ' . $this->safe($n['nickname']);
            if ($n['given_hebrew']) {
                $out[] = '2 TRAN ' . $this->safe($n['given_hebrew']);
                $out[] = '3 LANG he';
            }
        }
        if ($p['sex']) $out[] = '1 SEX ' . $p['sex'];
        if ($p['resn'] && $p['resn'] !== 'none') $out[] = '1 RESN ' . strtoupper($p['resn']);

        foreach ($arbor->model('persons')->externalIds((int) $p['id']) as $ex) {
            $out[] = '1 EXID ' . $this->safe($ex['external_id']);
            $out[] = '2 TYPE ' . $this->safe($ex['id_type']);
        }
        foreach ($arbor->model('events')->forPerson((int) $p['id']) as $e) {
            $tag = array_search($e['event_type'], $this->eventTagMap, true);
            if (!$tag) $tag = 'EVEN';
            $out[] = '1 ' . $tag . ($tag === 'EVEN' ? ' ' . $this->safe($e['event_subtype'] ?: $e['title']) : '');
            if ($e['event_subtype']) $out[] = '2 TYPE ' . $this->safe($e['event_subtype']);
            if ($e['event_date']) {
                $out[] = '2 DATE ' . $this->formatDate($e['event_date'], (bool) $e['event_date_approx']);
                if ($e['event_date_sort']) $out[] = '2 SDATE ' . $this->formatDate($e['event_date_sort']);
            } elseif ($e['event_date_phrase']) {
                $out[] = '2 DATE';
                $out[] = '3 PHRASE ' . $this->safe($e['event_date_phrase']);
            }
            $place = $e['event_place_str'] ?: ($e['event_place_id'] ? '@L' . $e['event_place_id'] . '@' : '');
            if ($place) {
                if (strpos($place, '@L') === 0) $out[] = '2 PLAC ' . $place;
                else $out[] = '2 PLAC ' . $this->safe($place);
            }
            if ($e['cause']) $out[] = '2 CAUS ' . $this->safe($e['cause']);
            foreach ($arbor->model('associations')->forEvent((int) $e['id']) as $a) {
                if ($a['related_id']) {
                    $out[] = '2 ASSO @I' . $a['related_id'] . '@';
                    $out[] = '3 ROLE ' . $a['role'] . ($a['role_phrase'] ? ' (' . $this->safe($a['role_phrase']) . ')' : '');
                }
            }
        }
        foreach ($arbor->model('photos')->forPerson((int) $p['id']) as $ph) {
            $url = $arbor->model('photos')->url($ph);
            $out[] = '1 OBJE';
            $out[] = '2 FILE ' . $this->safe($url);
            $out[] = '3 FORM image/jpeg';
            if ($ph['crop_x'] !== null && $ph['crop_w']) {
                $out[] = '2 CROP';
                $out[] = '3 LEFT ' . (int) $ph['crop_x'];
                $out[] = '3 TOP ' . (int) $ph['crop_y'];
                $out[] = '3 WIDTH ' . (int) $ph['crop_w'];
                $out[] = '3 HEIGHT ' . (int) $ph['crop_h'];
            }
        }
        if ($p['bio']) $out[] = '1 NOTE ' . $this->safe($p['bio']);
        return $out;
    }

    protected function exportUnionV7(array $u): array
    {
        return $this->exportUnionV551($u);
    }

    protected function exportPlaceV7(array $pl, int $treeId): array
    {
        $out = ['0 @L' . $pl['id'] . '@ _LOC'];
        $out[] = '1 NAME ' . $this->safe($pl['canonical_name']);
        if ($pl['latitude']) $out[] = '1 MAP';
        if ($pl['latitude']) $out[] = '2 LATI ' . $pl['latitude'];
        if ($pl['longitude']) $out[] = '2 LONG ' . $pl['longitude'];
        if ($pl['parent_id']) $out[] = '1 _LOC @L' . $pl['parent_id'] . '@';
        foreach ($this->arbor->model('places')->names((int) $pl['id']) as $n) {
            $out[] = '1 NAME ' . $this->safe($n['name']);
            if ($n['language']) $out[] = '2 LANG ' . $n['language'];
            if ($n['date_from']) $out[] = '2 DATE FROM ' . $this->formatDate($n['date_from']);
        }
        return $out;
    }

    protected function formatDate(?string $iso, bool $approx = false): string
    {
        if (!$iso) return '';
        $parts = explode('-', $iso);
        $months = ['','JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
        $year = (int) ($parts[0] ?? 0);
        $month = (int) ($parts[1] ?? 0);
        $day = (int) ($parts[2] ?? 0);
        $out = '';
        if ($day) $out .= $day . ' ';
        if ($month) $out .= $months[$month] . ' ';
        $out .= $year;
        return ($approx ? 'ABT ' : '') . trim($out);
    }

    protected function safe(string $s): string
    {
        return str_replace(["\r", "\n"], ' ', $s);
    }

    /* ============== PARSER ============== */

    protected function parseRecords(string $content): array
    {
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        $lines = explode("\n", $content);

        $records = [];
        $current = null;
        $stack = [];

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if ($line === '') continue;
            if (!preg_match('/^(\d+)\s+(@\w+@\s+)?(\w+)(\s+(.*))?$/u', $line, $m)) continue;
            $level = (int) $m[1];
            $xref = trim($m[2] ?? '');
            $tag = $m[3];
            $value = $m[5] ?? '';

            $node = ['level' => $level, 'tag' => $tag, 'xref' => $xref, 'value' => $value, 'children' => []];

            if ($level === 0) {
                if ($current !== null) $records[] = $current;
                $current = $node;
                $stack = [&$current];
                continue;
            }
            while (count($stack) > $level) array_pop($stack);
            $parentRef = &$stack[count($stack) - 1];
            $parentRef['children'][] = $node;
            $newRef = &$parentRef['children'][count($parentRef['children']) - 1];
            if ($tag === 'CONT' || $tag === 'CONC') {
                $parentRef['value'] .= ($tag === 'CONT' ? "\n" : '') . $value;
                continue;
            }
            $stack[] = &$newRef;
            unset($parentRef, $newRef);
        }
        if ($current !== null) $records[] = $current;
        return $records;
    }

    protected function findSub(array $rec, string $tag): ?array
    {
        foreach ($rec['children'] ?? [] as $c) if ($c['tag'] === $tag) return $c;
        return null;
    }

    protected function findAll(array $rec, string $tag): array
    {
        $out = [];
        foreach ($rec['children'] ?? [] as $c) if ($c['tag'] === $tag) $out[] = $c;
        return $out;
    }

    protected function subValue(array $rec, string $path): string
    {
        $parts = explode('.', $path);
        $node = $rec;
        foreach ($parts as $p) {
            $found = null;
            foreach ($node['children'] ?? [] as $c) if ($c['tag'] === $p) { $found = $c; break; }
            if (!$found) return '';
            $node = $found;
        }
        return $node['value'] ?? '';
    }
}
