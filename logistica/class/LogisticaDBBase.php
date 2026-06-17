<?php
abstract class LogisticaDBBase
{
    protected $conn;
    protected string $connKey;
    private int $cacheTtl = 300;

    public function __construct(string $connKey)
    {
        $this->connKey = $connKey;
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Conexion.php';
        $cid = new Conexion();
        $this->conn = $cid->conectar($connKey);
        if (!$this->conn) throw new RuntimeException("No se pudo conectar ({$connKey}).");
    }

    protected function normalizeRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) {
                $row[$k] = $v->format('Y-m-d');
            }
        }
        return $row;
    }

    protected function query(string $sql, array $params = []): array
    {
        $cacheKey = $this->cacheKey($sql, $params);
        if (empty($_GET['_nocache'])) {
            $cached = $this->cacheGet($cacheKey);
            if ($cached !== null) return $cached;
        }

        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new RuntimeException('SQL error: ' . ($err[0]['message'] ?? 'unknown'));
        }
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $rows[] = $this->normalizeRow($row); }
        sqlsrv_free_stmt($stmt);
        $this->cacheSet($cacheKey, $rows);
        return $rows;
    }

    protected function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    protected function execSP(string $spCall, array $params = []): array
    {
        $cacheKey = $this->cacheKey($spCall, $params);
        if (empty($_GET['_nocache'])) {
            $cached = $this->cacheGet($cacheKey);
            if ($cached !== null) return $cached;
        }

        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $stmt = sqlsrv_query($this->conn, $spCall, $params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new RuntimeException('SP error: ' . ($err[0]['message'] ?? 'unknown'));
        }
        $sets = [];
        do {
            $rows = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $rows[] = $this->normalizeRow($row); }
            $sets[] = $rows;
        } while (sqlsrv_next_result($stmt));
        sqlsrv_free_stmt($stmt);
        $this->cacheSet($cacheKey, $sets);
        return $sets;
    }

    private function cacheKey(string $spCall, array $params): string
    {
        return sha1($this->connKey . '|' . $spCall . '|' . json_encode($params, JSON_UNESCAPED_UNICODE));
    }

    private function cachePath(string $key): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'bi_logistica_' . $this->connKey . '_' . $key . '.json';
    }

    private function cacheGet(string $key): ?array
    {
        $path = $this->cachePath($key);
        if (!is_file($path)) return null;
        if (time() - filemtime($path) > $this->cacheTtl) return null;
        $json = @file_get_contents($path);
        if ($json === false || $json === '') return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function cacheSet(string $key, array $data): void
    {
        @file_put_contents($this->cachePath($key), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK), LOCK_EX);
    }
}
