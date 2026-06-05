<?php namespace ProcessWire;

class ArborName extends Wire
{
    protected Arbor $arbor;

    protected array $fields = [
        'person_id','name_type','prefix','given','nickname','surname_pfx',
        'surname','suffix','patronymic','given_hebrew','father_hebrew',
        'matronymic','kinui_id','script','language','dm_soundex','std_soundex',
        'surname_adopted_date','date_from','date_to','sort','notes',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_names WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forPerson(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_names WHERE person_id = :p ORDER BY sort, id");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function primary(int $personId): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_names
                              WHERE person_id = :p
                              ORDER BY (name_type = 'BIRTH') DESC, sort, id LIMIT 1");
        $stmt->execute([':p' => $personId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data, ?int $id = null): int
    {
        if ($this->arbor->dmSoundex) {
            $compose = trim(($data['given'] ?? '') . ' ' . ($data['surname'] ?? ''));
            if ($compose !== '') {
                $data['dm_soundex'] = self::daitchMokotoff($compose);
                $data['std_soundex'] = self::soundex($compose);
            }
        }

        $db = $this->wire('database');
        $dateCols = ['surname_adopted_date','date_from','date_to'];
        $intCols  = ['person_id','kinui_id','sort'];

        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_names SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields);
            $vals = ':' . implode(', :', $this->fields);
            $stmt = $db->prepare("INSERT INTO arbor_names ($cols) VALUES ($vals)");
        }
        foreach ($this->fields as $f) {
            $val = $data[$f] ?? null;
            if (in_array($f, $dateCols, true)) {
                $val = $val ?: null;
                $stmt->bindValue(":$f", $val, $val === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            } elseif (in_array($f, $intCols, true)) {
                if ($f === 'sort' && ($val === null || $val === '')) $val = 0;
                $val = $val === null || $val === '' ? null : (int) $val;
                $stmt->bindValue(":$f", $val, $val === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$f", (string) ($val ?? ''));
            }
        }
        $stmt->execute();
        return $id ?: (int) $db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_names WHERE id = :id")
            ->execute([':id' => $id]);
    }

    /**
     * Standard PHP soundex wrapped for Cyrillic transliteration fallback.
     */
    public static function soundex(string $name): string
    {
        return soundex(self::transliterate($name));
    }

    /**
     * Daitch-Mokotoff Soundex (simplified). Returns up to 6 digits per token,
     * multiple variants joined by space when alternative codings exist.
     *
     * Reference: https://www.jewishgen.org/InfoFiles/soundex.html
     */
    public static function daitchMokotoff(string $name): string
    {
        $clean = mb_strtolower(self::transliterate($name));
        $clean = preg_replace('/[^a-z\s]/u', '', $clean);
        $tokens = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) return '';
        $out = [];
        foreach ($tokens as $t) $out[] = self::dmEncode($t);
        return implode(' ', $out);
    }

    protected static function dmEncode(string $w): string
    {
        $rules = [
            // [pattern, start, before-vowel, other]
            ['schtsch', '2', '4', '4'], ['schtsh', '2', '4', '4'], ['schtch', '2', '4', '4'],
            ['shtch',   '2', '4', '4'], ['shtsh',  '2', '4', '4'], ['stsch',  '2', '4', '4'],
            ['ttsch',   '4', '4', '4'], ['zhdzh',  '2', '4', '4'],
            ['shch',    '2', '4', '4'], ['scht',   '2', '4', '4'], ['schd',   '2', '43','43'],
            ['stch',    '2', '4', '4'], ['strz',   '2', '4', '4'], ['strs',   '2', '4', '4'],
            ['stsh',    '2', '4', '4'], ['szcz',   '2', '4', '4'], ['szcs',   '2', '4', '4'],
            ['tsch',    '4', '4', '4'], ['ttsz',   '4', '4', '4'], ['zdzh',   '2', '4', '4'],
            ['zhdz',    '2', '4', '4'],
            ['cz',  '4', '4', '4'], ['cs',  '4', '4', '4'], ['ch',  '5', '5', '5'],
            ['ck',  '5', '5', '5'], ['dt',  '3', '3', '3'], ['dzh', '4', '4', '4'],
            ['dzs', '4', '4', '4'], ['dz',  '4', '4', '4'], ['ds',  '4', '4', '4'],
            ['kh',  '5', '5', '5'], ['ks',  '5', '54','54'], ['ph',  '7', '7', '7'],
            ['pf',  '7', '7', '7'], ['sch', '4', '4', '4'], ['sh',  '4', '4', '4'],
            ['st',  '2', '43','43'], ['sz',  '4', '4', '4'], ['th',  '3', '3', '3'],
            ['ts',  '4', '4', '4'], ['tz',  '4', '4', '4'], ['ttz', '4', '4', '4'],
            ['tch', '4', '4', '4'], ['zd',  '2', '43','43'], ['zh',  '4', '4', '4'],
            ['zs',  '4', '4', '4'],
            ['a','0','', ''], ['e','0','', ''], ['i','0','', ''], ['o','0','', ''],
            ['u','0','', ''], ['y','1','', ''], ['j','1','1',''], ['b','7','7','7'],
            ['c','5','5','5'], ['d','3','3','3'], ['f','7','7','7'], ['g','5','5','5'],
            ['h','5','5',''],  ['k','5','5','5'], ['l','8','8','8'], ['m','6','6','6'],
            ['n','6','6','6'], ['p','7','7','7'], ['q','5','5','5'], ['r','9','9','9'],
            ['s','4','4','4'], ['t','3','3','3'], ['v','7','7','7'], ['w','7','7','7'],
            ['x','5','54','54'], ['z','4','4','4'],
        ];
        $vowels = ['a','e','i','o','u','y'];
        $code = '';
        $pos = 0;
        $len = strlen($w);
        $last = '';
        while ($pos < $len) {
            $matched = null;
            foreach ($rules as $r) {
                $p = $r[0];
                $pl = strlen($p);
                if ($pl + $pos > $len) continue;
                if (substr($w, $pos, $pl) === $p) { $matched = $r; break; }
            }
            if (!$matched) { $pos++; continue; }
            [$pat, $start, $before, $other] = $matched;
            $isStart = ($code === '');
            $next = $w[$pos + strlen($pat)] ?? '';
            $isVowelNext = in_array($next, $vowels, true);
            $value = $isStart ? $start : ($isVowelNext ? $before : $other);
            if ($value !== '' && $value !== $last) {
                $code .= $value;
                $last = substr($value, -1);
            } elseif ($value === '') {
                $last = '';
            }
            $pos += strlen($pat);
            if (strlen($code) >= 6) break;
        }
        return str_pad(substr($code, 0, 6), 6, '0');
    }

    protected static function transliterate(string $s): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo',
            'ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m',
            'н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
            'ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
            'і'=>'i','ї'=>'yi','є'=>'ye','ґ'=>'g',
        ];
        $s = mb_strtolower($s);
        return strtr($s, $map);
    }
}
