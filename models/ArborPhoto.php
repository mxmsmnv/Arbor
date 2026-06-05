<?php namespace ProcessWire;

class ArborPhoto extends Wire
{
    protected Arbor $arbor;
    protected array $fields = [
        'person_id','tree_id','filename','title','description','is_profile',
        'year','crop_x','crop_y','crop_w','crop_h','sort',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_photos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forPerson(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_photos WHERE person_id = :p ORDER BY sort, id");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function profile(int $personId): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_photos WHERE person_id = :p AND is_profile = 1 LIMIT 1");
        $stmt->execute([':p' => $personId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) return $row;
        $stmt = $db->prepare("SELECT * FROM arbor_photos WHERE person_id = :p ORDER BY sort, id LIMIT 1");
        $stmt->execute([':p' => $personId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        $defaults = ['is_profile' => 0, 'sort' => 0];

        if (!empty($data['is_profile']) && !empty($data['person_id'])) {
            $stmt = $db->prepare("UPDATE arbor_photos SET is_profile = 0 WHERE person_id = :p");
            $stmt->execute([':p' => (int) $data['person_id']]);
        }

        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_photos SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields) . ',created';
            $vals = ':' . implode(', :', $this->fields) . ', :created';
            $stmt = $db->prepare("INSERT INTO arbor_photos ($cols) VALUES ($vals)");
            $stmt->bindValue(':created', time(), \PDO::PARAM_INT);
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? ($defaults[$f] ?? null);
            if (in_array($f, ['person_id','tree_id','is_profile','year','crop_x','crop_y','crop_w','crop_h','sort'], true)) {
                $v = ($v === null || $v === '') ? null : (int) $v;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$f", (string) ($v ?? ''));
            }
        }
        $stmt->execute();
        $newId = $id ?: (int) $db->lastInsertId();
        if (!$id) $this->arbor->photoAfterUpload($newId, $data);
        return $newId;
    }

    public function delete(int $id): bool
    {
        $row = $this->get($id);
        if (!$row) return false;
        $dir = $this->arbor->uploadDir((int) $row['tree_id'], (int) $row['person_id']);
        $file = $dir . $row['filename'];
        if ($row['filename'] && is_file($file)) @unlink($file);
        return $this->wire('database')->prepare("DELETE FROM arbor_photos WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public function setProfile(int $id): bool
    {
        $row = $this->get($id);
        if (!$row) return false;
        $db = $this->wire('database');
        $stmt = $db->prepare("UPDATE arbor_photos SET is_profile = 0 WHERE person_id = :p");
        $stmt->execute([':p' => (int) $row['person_id']]);
        $stmt = $db->prepare("UPDATE arbor_photos SET is_profile = 1 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function url(array $row): string
    {
        return $this->arbor->uploadUrl((int) $row['tree_id'], (int) $row['person_id']) . $row['filename'];
    }
}
