<?php namespace ProcessWire;

class ArborTree extends Wire
{
    protected Arbor $arbor;

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function all(): array
    {
        $db = $this->wire('database');
        $stmt = $db->query("SELECT * FROM arbor_trees ORDER BY name");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_trees WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        $fields = ['name','description','owner_id','is_public','settings'];
        $now = time();
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
            $sql = "UPDATE arbor_trees SET $set, modified = :modified WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
            foreach ($fields as $f) $stmt->bindValue(":$f", $data[$f] ?? '');
            $stmt->execute();
            return $id;
        }
        $cols = implode(',', $fields) . ',created,modified';
        $vals = ':' . implode(', :', $fields) . ', :created, :modified';
        $stmt = $db->prepare("INSERT INTO arbor_trees ($cols) VALUES ($vals)");
        foreach ($fields as $f) $stmt->bindValue(":$f", $data[$f] ?? '');
        $stmt->bindValue(':created', $now, \PDO::PARAM_INT);
        $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
        $stmt->execute();
        return (int) $db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("DELETE FROM arbor_trees WHERE id = :id");
        $ok = $stmt->execute([':id' => $id]);
        if ($ok) $this->arbor->removeUploadDir($id);
        return $ok;
    }

    public function settings(int $id): array
    {
        $row = $this->get($id);
        if (!$row || empty($row['settings'])) return [];
        $data = json_decode($row['settings'], true);
        return is_array($data) ? $data : [];
    }
}
