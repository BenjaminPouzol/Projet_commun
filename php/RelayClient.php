<?php
/**
 * RelayClient — Remplace PDO quand la BDD distante n'est pas accessible en direct.
 *
 * Envoie les requêtes SQL via HTTP au relay.php déployé sur le serveur de l'école.
 * Implémente la même interface que PDO (prepare/query/exec/fetch/fetchAll…)
 * pour que le reste du code n'ait rien à changer.
 */

class RelayStatement
{
    private RelayClient $client;
    private string      $sql;
    private mixed       $result   = null;
    private int         $rowCount = 0;

    public function __construct(RelayClient $client, string $sql)
    {
        $this->client = $client;
        $this->sql    = $sql;
    }

    public function execute(array $params = []): bool
    {
        $resp           = $this->client->send('all', $this->sql, $params);
        $this->result   = $resp['rows'] ?? [];
        $this->rowCount = (int)($resp['rowCount'] ?? 0);
        return true;
    }

    public function fetch(): mixed
    {
        if (!is_array($this->result) || empty($this->result)) return false;
        return array_shift($this->result);
    }

    public function fetchAll(): array
    {
        return is_array($this->result) ? $this->result : [];
    }

    public function fetchColumn(): mixed
    {
        $resp = $this->client->send('column', $this->sql, []);
        return $resp['rows'] ?? false;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }
}


class RelayClient
{
    private string $url;
    private string $secret;

    public function __construct(string $url, string $secret)
    {
        $this->url    = $url;
        $this->secret = $secret;
    }

    // ── Envoi d'une requête au relai ──────────────────────────────────────────
    public function send(string $action, string $sql, array $params = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL requis pour le mode relai (activer php_curl dans php.ini)');
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['action' => $action, 'sql' => $sql, 'params' => $params]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Relay-Key: ' . $this->secret,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false, // school cert may be self-signed
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlErr !== '') {
            throw new RuntimeException("Relai injoignable : $curlErr");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("Relai HTTP $httpCode : $raw");
        }

        $data = json_decode($raw, true);
        if (!($data['ok'] ?? false)) {
            throw new RuntimeException('Erreur relai : ' . ($data['erreur'] ?? $raw));
        }

        return $data;
    }

    // ── Initialisation du schéma (envoie tous les CREATE TABLE en une fois) ───
    public function initSchema(array $sqls): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL requis pour le mode relai');
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['action' => 'init_schema', 'sqls' => $sqls]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Relay-Key: ' . $this->secret,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            throw new RuntimeException("Échec init_schema via relai (HTTP $code)");
        }
    }

    // ── Interface PDO minimale ────────────────────────────────────────────────
    public function prepare(string $sql): RelayStatement
    {
        return new RelayStatement($this, $sql);
    }

    public function query(string $sql): RelayStatement
    {
        $stmt = new RelayStatement($this, $sql);
        $stmt->execute([]);
        return $stmt;
    }

    public function exec(string $sql): int
    {
        return (int)($this->send('exec', $sql)['rowCount'] ?? 0);
    }

    // Transactions : pas gérées côté relai, no-op pour compatibilité
    public function beginTransaction(): bool { return true; }
    public function commit(): bool           { return true; }
    public function rollBack(): bool         { return true; }

    public function setAttribute(int $attr, mixed $value): bool { return true; }
    public function getAttribute(int $attr): mixed               { return null; }
    public function lastInsertId(): string                       { return '0'; }
}
