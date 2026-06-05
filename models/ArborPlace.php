<?php namespace ProcessWire;

class ArborPlace extends Wire
{
    protected Arbor $arbor;

    protected array $fields = [
        'tree_id','canonical_name','parent_id','place_type','latitude','longitude',
        'geonames_id','gov_id','jewishgen_id','wikipedia_url','notes',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_places WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forTree(int $treeId, ?int $parentId = null): array
    {
        $db = $this->wire('database');
        if ($parentId === null) {
            $stmt = $db->prepare("SELECT * FROM arbor_places WHERE tree_id = :t AND parent_id IS NULL ORDER BY canonical_name");
            $stmt->execute([':t' => $treeId]);
        } else {
            $stmt = $db->prepare("SELECT * FROM arbor_places WHERE tree_id = :t AND parent_id = :p ORDER BY canonical_name");
            $stmt->execute([':t' => $treeId, ':p' => $parentId]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function allForTree(int $treeId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_places WHERE tree_id = :t ORDER BY canonical_name");
        $stmt->execute([':t' => $treeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findByName(int $treeId, string $name): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_places WHERE tree_id = :t AND canonical_name = :n LIMIT 1");
        $stmt->execute([':t' => $treeId, ':n' => $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        $intCols = ['tree_id','parent_id'];
        $floatCols = ['latitude','longitude'];

        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_places SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields) . ',created';
            $vals = ':' . implode(', :', $this->fields) . ', :created';
            $stmt = $db->prepare("INSERT INTO arbor_places ($cols) VALUES ($vals)");
            $stmt->bindValue(':created', time(), \PDO::PARAM_INT);
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? null;
            if (in_array($f, $intCols, true)) {
                $v = ($v === null || $v === '') ? null : (int) $v;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            } elseif (in_array($f, $floatCols, true)) {
                $v = ($v === null || $v === '') ? null : (float) $v;
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
        return $this->wire('database')->prepare("DELETE FROM arbor_places WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public function names(int $placeId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_place_names WHERE place_id = :p ORDER BY date_from, name");
        $stmt->execute([':p' => $placeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function saveName(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        $fields = ['place_id','name','language','script','date_from','date_to','name_type'];
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
            $stmt = $db->prepare("UPDATE arbor_place_names SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $stmt = $db->prepare("INSERT INTO arbor_place_names (" . implode(',', $fields) . ") VALUES (:" . implode(', :', $fields) . ")");
        }
        foreach ($fields as $f) {
            $v = $data[$f] ?? null;
            if ($f === 'place_id') {
                $stmt->bindValue(":$f", (int) $v, \PDO::PARAM_INT);
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

    public function deleteName(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_place_names WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public function jurisdictions(int $placeId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_place_jurisdictions WHERE place_id = :p ORDER BY date_from");
        $stmt->execute([':p' => $placeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function saveJurisdiction(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        $fields = ['place_id','country','region','date_from','date_to','notes'];
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
            $stmt = $db->prepare("UPDATE arbor_place_jurisdictions SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $stmt = $db->prepare("INSERT INTO arbor_place_jurisdictions (" . implode(',', $fields) . ") VALUES (:" . implode(', :', $fields) . ")");
        }
        foreach ($fields as $f) {
            $v = $data[$f] ?? null;
            if ($f === 'place_id') {
                $stmt->bindValue(":$f", (int) $v, \PDO::PARAM_INT);
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

    public function deleteJurisdiction(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_place_jurisdictions WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public function fullPath(int $placeId): string
    {
        $db = $this->wire('database');
        $names = [];
        $current = $placeId;
        $depth = 0;
        while ($current && $depth++ < 10) {
            $stmt = $db->prepare("SELECT canonical_name, parent_id FROM arbor_places WHERE id = :id");
            $stmt->execute([':id' => $current]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) break;
            $names[] = $row['canonical_name'];
            $current = $row['parent_id'] ? (int) $row['parent_id'] : null;
        }
        return implode(', ', $names);
    }
}
