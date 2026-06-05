<?php namespace ProcessWire;

class ArborCitizenship extends Wire
{
    protected Arbor $arbor;
    protected array $fields = ['person_id','country','date_from','date_to','is_current','notes'];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_citizenships WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forPerson(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_citizenships WHERE person_id = :p ORDER BY date_from");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_citizenships SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields);
            $vals = ':' . implode(', :', $this->fields);
            $stmt = $db->prepare("INSERT INTO arbor_citizenships ($cols) VALUES ($vals)");
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? null;
            if ($f === 'person_id' || $f === 'is_current') {
                $stmt->bindValue(":$f", (int) ($v ?? 0), \PDO::PARAM_INT);
            } elseif (in_array($f, ['date_from','date_to'], true)) {
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
        return $this->wire('database')->prepare("DELETE FROM arbor_citizenships WHERE id = :id")
            ->execute([':id' => $id]);
    }
}
