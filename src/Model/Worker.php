<?php

namespace App\Model;

class Worker extends Model {

    protected function getTable(): string {
        return 'workers';
    }

    // The API key hash must never leave the database layer (GET/PUT responses are built from this)
    public function getById($id) {
        $row = parent::getById($id);
        unset($row['api_key_hash']);

        return $row;
    }

    // Looks up a worker by the SHA-256 of their API key. Returns the id, or null if no worker has it.
    // The plain key never reaches the database: the caller hashes it first.
    public function findIdByApiKeyHash(string $hash): ?int {
        $stmt = $this->conn->prepare("SELECT id FROM workers WHERE api_key_hash = :hash");
        $stmt->execute(['hash' => $hash]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

}
