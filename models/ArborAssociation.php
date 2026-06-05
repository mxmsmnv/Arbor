<?php namespace ProcessWire;

class ArborAssociation extends Wire
{
    protected Arbor $arbor;
    protected array $fields = ['person_id','related_id','event_id','role','role_phrase','notes'];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_associations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forPerson(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_associations WHERE person_id = :p ORDER BY role");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function forEvent(int $eventId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_associations WHERE event_id = :e");
        $stmt->execute([':e' => $eventId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_associations SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields);
            $vals = ':' . implode(', :', $this->fields);
            $stmt = $db->prepare("INSERT INTO arbor_associations ($cols) VALUES ($vals)");
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? null;
            if (in_array($f, ['person_id','related_id','event_id'], true)) {
                $v = ($v === null || $v === '') ? null : (int) $v;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$f", (string) ($v ?? ''));
            }
        }
        $stmt->execute();
        $newId = $id ?: (int) $db->lastInsertId();
        $this->arbor->associationAfterSave($newId, $data);
        return $newId;
    }

    public function delete(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_associations WHERE id = :id")
            ->execute([':id' => $id]);
    }
}
