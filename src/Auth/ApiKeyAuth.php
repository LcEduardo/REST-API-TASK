<?php

namespace App\Auth;

// Descobre quem está chamando a API a partir da chave enviada no header X-API-Key
final class ApiKeyAuth
{
    /**
     * @param array<string, ?string> $keys papel => chave (ex.: ['boss' => '...', 'worker' => '...'])
     */
    public function __construct(private array $keys)
    {
    }

    // Devolve o papel dono da chave ('boss', 'worker'...) ou null se a chave não vale
    public function roleFor(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        foreach ($this->keys as $role => $expected) {
            // Chave não configurada no .env não pode liberar acesso (senão um header vazio passaria)
            if ($expected === null || $expected === '') {
                continue;
            }

            // hash_equals compara em tempo constante, para não vazar a chave pelo tempo de resposta
            if (hash_equals($expected, $key)) {
                return $role;
            }
        }

        return null;
    }
}
