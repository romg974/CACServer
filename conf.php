<?php
try {
     $pdo = new PDO('mysql:host=localhost;dbname=cac', 'root', '');
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
