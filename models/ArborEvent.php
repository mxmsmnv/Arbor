<?php namespace ProcessWire;

class ArborEvent extends Wire
{
    protected Arbor $arbor;

    protected array $fields = [
        'person_id','union_id','tree_id','event_type','event_subtype','title',
        'event_date','event_date_approx','event_date_phrase','event_date_cal',
        'event_date_hebrew','event_date_sort','event_place_id','event_place_str',
        'agency','cause','age_at_event','description','source_note',
        'is_private','resn','quality','sort',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_events WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forPerson(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_events WHERE person_id = :p
            ORDER BY COALESCE(event_date_sort, event_date) IS NULL, COALESCE(event_date_sort, event_date), sort, id");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function forUnion(int $unionId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_events WHERE union_id = :u
            ORDER BY COALESCE(event_date_sort, event_date) IS NULL, COALESCE(event_date_sort, event_date), sort, id");
        $stmt->execute([':u' => $unionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findByType(int $personId, string $type): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_events WHERE person_id = :p AND event_type = :t LIMIT 1");
        $stmt->execute([':p' => $personId, ':t' => $type]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data, ?int $id = null): int
    {
        $arbor = $this->arbor;
        $db = $this->wire('database');
        $now = time();
        $dateCols = ['event_date','event_date_sort'];
        $intCols  = ['person_id','union_id','tree_id','event_place_id',
                     'event_date_approx','is_private','quality','sort'];
        $defaults = [
            'event_date_cal' => 'gregorian',
            'event_date_approx' => 0,
            'is_private' => 0,
            'quality' => 2,
            'sort' => 0,
        ];

        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_events SET $set, modified = :modified WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields) . ',created,modified';
            $vals = ':' . implode(', :', $this->fields) . ', :created, :modified';
            $stmt = $db->prepare("INSERT INTO arbor_events ($cols) VALUES ($vals)");
            $stmt->bindValue(':created', $now, \PDO::PARAM_INT);
            $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? ($defaults[$f] ?? null);
            if (in_array($f, $dateCols, true)) {
                $v = $v ?: null;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            } elseif (in_array($f, $intCols, true)) {
                $v = ($v === null || $v === '') ? null : (int) $v;
                if (in_array($f, ['tree_id'], true) && $v === null) $v = 0;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$f", (string) ($v ?? ''));
            }
        }
        $stmt->execute();
        $eventId = $id ?: (int) $db->lastInsertId();

        if (!empty($data['fields']) && is_array($data['fields'])) {
            $this->setFields($eventId, $data['fields']);
        }
        $arbor->eventAfterSave($eventId, $data);
        return $eventId;
    }

    public function delete(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_events WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public function getFields(int $eventId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT field_key, field_value FROM arbor_event_fields WHERE event_id = :e");
        $stmt->execute([':e' => $eventId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[$r['field_key']] = $r['field_value'];
        return $out;
    }

    public function setFields(int $eventId, array $fields): void
    {
        $db = $this->wire('database');
        $del = $db->prepare("DELETE FROM arbor_event_fields WHERE event_id = :e");
        $del->execute([':e' => $eventId]);
        $ins = $db->prepare("INSERT INTO arbor_event_fields (event_id, field_key, field_value) VALUES (:e, :k, :v)");
        foreach ($fields as $k => $v) {
            if ($v === '' || $v === null) continue;
            $ins->execute([':e' => $eventId, ':k' => (string) $k, ':v' => (string) $v]);
        }
    }
}
