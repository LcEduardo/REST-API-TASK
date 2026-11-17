<?php

namespace App\Model;

use Exception;
use PDO;

class Task extends Model{

    protected function getTable(): string {
        return 'tasks';
    }

    public function getById($id) {
        // Unico select com JOIN pois é apenas uma consulta
        $stmt = $this->conn->prepare(
            "SELECT t.id, t.title, t.description, t.status, t.created, t.modified, w.name as \"worker\", b.name as \"boss\" FROM tasks t
                LEFT JOIN workers w on w.id = t.worker_id
                LEFT JOIN bosses b on b.id = t.boss_id
             WHERE t.id = :id;"
            );
        $stmt->execute(['id' => $id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
        if ($task === false) {
            throw new Exception("Task with id $id not found", 404);
        }

        return $task;

    }

    // ORDER BY is required with LIMIT/OFFSET:
    // Without it, Postgres does not guarantee the order, and the same task could appear on two pages.
    public function getAll(int $limit, int $offset): array {
        $stmt = $this->conn->prepare(
            "SELECT t.id, t.title, t.description, t.status, t.created, t.modified, w.name as \"worker\", b.name as \"boss\" FROM tasks t
                LEFT JOIN workers w on w.id = t.worker_id
                LEFT JOIN bosses b on b.id = t.boss_id
             ORDER BY t.id
             LIMIT :limit OFFSET :offset;"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}