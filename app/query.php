<?php

declare(strict_types=1);

/**
 * Exécute une requête préparée et retourne toutes ses lignes.
 */
function rows(string $sql, array $parameters = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchAll();
}

/**
 * Exécute une requête préparée et retourne sa première ligne.
 */
function one(string $sql, array $parameters = []): ?array
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetch() ?: null;
}
