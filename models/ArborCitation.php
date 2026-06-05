<?php namespace ProcessWire;

class ArborCitation extends Wire
{
    protected Arbor $arbor;
    protected array $fields = [
        'source_id','person_id','event_id','page_ref','folio_verso','quality',
        'accessed_date','transcription','translation','photo_id','document_id','researcher','notes',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_citations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forSource(int $sourceId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_citations WHERE source_id = :s");
        $stmt->execute([':s' => $sourceId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function forPerson(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_citations WHERE person_id = :p");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function forEvent(int $eventId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_citations WHERE event_id = :e");
        $stmt->execute([':e' => $eventId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        $intCols = ['source_id','person_id','event_id','photo_id','document_id','folio_verso','quality'];
        $defaults = ['folio_verso' => 0, 'quality' => 2];
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_citations SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields);
            $vals = ':' . implode(', :', $this->fields);
            $stmt = $db->prepare("INSERT INTO arbor_citations ($cols) VALUES ($vals)");
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? ($defaults[$f] ?? null);
            if (in_array($f, $intCols, true)) {
                if ($f === 'source_id') {
                    $stmt->bindValue(":$f", (int) ($v ?? 0), \PDO::PARAM_INT);
                } else {
                    $v = ($v === null || $v === '') ? null : (int) $v;
                    $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
                }
            } elseif ($f === 'accessed_date') {
                $v = $v ?: null;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            } else {
                $stmt->bindValue(":$f", (string) ($v ?? ''));
            }
        }
        $stmt->execute();
        return $id ?: (int) $db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_citations WHERE id = :id")
            ->execute([':id' => $id]);
    }
}
