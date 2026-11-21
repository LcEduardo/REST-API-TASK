<?php

namespace App\Controller;

use Exception;
use PDOException;

abstract class Controller
{
    protected function validateFields($name, $email): array {
        // name[]=a arrives as an array; trim() on an array throws TypeError (not an Exception)
        if (($name !== null && !is_string($name)) || ($email !== null && !is_string($email))) {
            throw new Exception("The name and email must be strings", 400);
        }

        $name = trim($name ?? '');
        // email is stored lowercase so the UNIQUE constraint doesn't treat Ana@x.com and ana@x.com as different
        $email = strtolower(trim($email ?? ''));

        if ($name === '') {
            throw new Exception("The name is required", 400);
        }

        // mb_strlen counts characters like Postgres VARCHAR does ("João" = 4, strlen would give 5)
        if (mb_strlen($name) > 100) {
            throw new Exception("The name must have at most 100 characters", 400);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new Exception("The email isn't valid", 400);
        }

        if (mb_strlen($email) > 150) {
            throw new Exception("The email must have at most 150 characters", 400);
        }

        return ['name' => $name, 'email' => $email];
    }

    protected function databaseError(PDOException $e): array {
        // 23505 = unique_violation (email already registered)
        if ($e->getCode() === '23505') {
            return [
                "status" => 409,
                "body" => ['error' => 'Email already registered'],
            ];
        }

        return [
            "status" => 500,
            "body" => ['error' => 'Database error'],
        ];
    }
}
