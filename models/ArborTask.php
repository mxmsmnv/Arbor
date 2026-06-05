<?php namespace ProcessWire;

class ArborTask extends Wire
{
    protected Arbor $arbor;
    protected array $fields = [
        'tree_id','person_id','event_id','source_id','task_type','title',
        'description','status','priority','due_date','assigned_to',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $stmt = $this->wire('database')->prepare("SELECT * FROM arbor_tasks WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forTree(int $treeId, ?string $status = null): array
    {
        $db = $this->wire('database');
        if ($status) {
            $stmt = $db->prepare("SELECT * FROM arbor_tasks WHERE tree_id = :t AND status = :s ORDER BY priority DESC, due_date");
            $stmt->execute([':t' => $treeId, ':s' => $status]);
        } else {
            $stmt = $db->prepare("SELECT * FROM arbor_tasks WHERE tree_id = :t ORDER BY status, priority DESC, due_date");
            $stmt->execute([':t' => $treeId]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function forPerson(int $personId): array
    {
        $stmt = $this->wire('database')->prepare("SELECT * FROM arbor_tasks WHERE person_id = :p AND status <> 'done' ORDER BY priority DESC");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        $intCols = ['tree_id','person_id','event_id','source_id'];
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_tasks SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields) . ',created';
            $vals = ':' . implode(', :', $this->fields) . ', :created';
            $stmt = $db->prepare("INSERT INTO arbor_tasks ($cols) VALUES ($vals)");
            $stmt->bindValue(':created', time(), \PDO::PARAM_INT);
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? null;
            if (in_array($f, $intCols, true)) {
                if ($f === 'tree_id') {
                    $stmt->bindValue(":$f", (int) ($v ?? 0), \PDO::PARAM_INT);
                } else {
                    $v = ($v === null || $v === '') ? null : (int) $v;
                    $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
                }
            } elseif ($f === 'due_date') {
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
        return $this->wire('database')->prepare("DELETE FROM arbor_tasks WHERE id = :id")
            ->execute([':id' => $id]);
    }
}
