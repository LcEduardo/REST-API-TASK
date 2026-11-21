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

}
