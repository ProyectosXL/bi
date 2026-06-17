<?php
require_once __DIR__ . '/LogisticaDBBase.php';

class LogisticaDBFactory
{
    public static function make(string $pais): LogisticaDBBase
    {
        if (strtoupper($pais) === 'UY') {
            require_once __DIR__ . '/LogisticaDB_UY.php';
            return new LogisticaDB_UY();
        }
        require_once __DIR__ . '/LogisticaDB.php';
        return new LogisticaDB();
    }
}
