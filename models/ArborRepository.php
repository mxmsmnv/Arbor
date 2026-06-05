<?php namespace ProcessWire;

class ArborRepository extends Wire
{
    protected Arbor $arbor;
    protected array $fields = [
        'tree_id','name','abbreviation','name_original','city','country',
        'address','website','finding_aids','hours','access_policy','notes',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_repositories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forTree(int $treeId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_repositories WHERE tree_id = :t ORDER BY name");
        $stmt->execute([':t' => $treeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_repositories SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields);
            $vals = ':' . implode(', :', $this->fields);
            $stmt = $db->prepare("INSERT INTO arbor_repositories ($cols) VALUES ($vals)");
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? null;
            if ($f === 'tree_id') {
                $stmt->bindValue(":$f", (int) $v, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$f", (string) ($v ?? ''));
            }
        }
        $stmt->execute();
        return $id ?: (int) $db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_repositories WHERE id = :id")
            ->execute([':id' => $id]);
    }
}
