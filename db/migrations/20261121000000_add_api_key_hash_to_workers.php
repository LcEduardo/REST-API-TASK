<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddApiKeyHashToWorkers extends AbstractMigration
{
    public function change(): void
    {
        // SHA-256 in hex = 64 characters. Nullable because workers created before this migration have no key.
        $this->table('workers')
            ->addColumn('api_key_hash', 'string', ['limit' => 64, 'null' => true])
            ->addIndex(['api_key_hash'], ['unique' => true])
            ->update();
    }
}
