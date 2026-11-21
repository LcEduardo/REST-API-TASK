<?php

namespace App\Controller;

use App\Model\Boss;
use Exception;
use PDOException;

class ControllerBoss extends Controller
{
    private Boss $boss;

    public function __construct(Boss $boss) {
        $this->boss = $boss;
    }

    public function getBoss($id) {
        try {

            $id = filter_var($id, FILTER_VALIDATE_INT);

            if ($id === false) {
                 throw new Exception("The boss_id isn't interger", 400);
            }

            $boss = $this->boss->getById($id);

        }catch(PDOException $e) {
            return [
                "status" => 500,
                "body" => ['error' => 'Database error'],
            ];
        }catch(Exception $e) {
            return [
                "status" => $e->getCode(),
                "body" => ['error' => $e->getMessage()],
            ];
        }

        return [
            "status" => 200,
            "body" => $boss,
        ];
    }

    public function createBoss($name, $email) {
        try {

            $data = $this->validateFields($name, $email);

            $bossId = $this->boss->create($data);

            $boss = $this->boss->getById($bossId);

        }catch(PDOException $e) {
            return $this->databaseError($e);
        }catch(Exception $e) {
            return [
                "status" => $e->getCode(),
                "body" => ['error' => $e->getMessage()],
            ];
        }

        return [
            "status" => 201,
            "body" => $boss,
        ];
    }

    // PUT substitui o registro inteiro: name e email são obrigatórios, com as mesmas regras do create
    public function updateBoss($id, $name, $email) {
        try {

            $id = filter_var($id, FILTER_VALIDATE_INT);

            if ($id === false) {
                 throw new Exception("The boss_id isn't interger", 400);
            }

            $data = $this->validateFields($name, $email);

            $this->boss->update($id, $data);

            $boss = $this->boss->getById($id);

        }catch(PDOException $e) {
            return $this->databaseError($e);
        }catch(Exception $e) {
            return [
                "status" => $e->getCode(),
                "body" => ['error' => $e->getMessage()],
            ];
        }

        return [
            "status" => 200,
            "body" => $boss,
        ];
    }

}
