<?php

declare(strict_types=1);

namespace Lamahive\Pidl\Model;

use Dotenv\Dotenv;
use Exception;
use PDO;
use PDOException;
use PDOStatement;
use function sprintf;
use function implode;
use function array_map;
use function array_fill;
use function count;

class Db
{
    protected string $host;
    protected string $port;
    protected string $name;
    protected string $user;
    protected string $password;
    protected string $charset;
    protected bool $throwException = true;
    protected ?PDO $pdo;

    /**
     * @throws Exception
     */
    public function __construct(bool $autoconfig = true) {
        if ($autoconfig) {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../../../../../', 'pidl.env');
            $dotenv->load();
            $dotenv->required(['PIDL_HOST', 'PIDL_PORT', 'PIDL_NAME', 'PIDL_USER', 'PIDL_PASSWORD', 'PIDL_CHARSET'])->notEmpty();

            $this->host = $_ENV['PIDL_HOST'];
            $this->port = $_ENV['PIDL_PORT'];
            $this->name = $_ENV['PIDL_NAME'];
            $this->user = $_ENV['PIDL_USER'];
            $this->password = $_ENV['PIDL_PASSWORD'];
            $this->charset = str_replace("uft", "utf", $_ENV['PIDL_CHARSET']);
        }
    }

    public function setConfig(
        string $host,
        string $port,
        string $name,
        string $user,
        string $password,
        string $charset
    ): void
    {
        $this->host = $host;
        $this->port = $port;
        $this->name = $name;
        $this->user = $user;
        $this->password = $password;
        $this->charset = $charset;
    }

    /**
     * @throws Exception
     */
    public function insert(string $table, array $columns, array $values, bool $update = false): void
    {
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $this->backtickArray($columns)),
            $this->getQuestionMarks(count($values))
        );

        if ($update) {
            $sql .= sprintf(' ON DUPLICATE KEY UPDATE %s', $this->getOnUpdate($columns));
        }

        $this->queryThrow($sql, ...$values);
    }

    /**
     * @throws Exception
     */
    public function query(string $sql, ...$params): bool
    {
        $result = true;

        try {
            $this->querySql($sql, $params);
        } catch (PDOException) {
            $result = false;
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    public function queryThrow(string $sql, ...$params): void
    {
        $this->querySql($sql, $params);
    }

    public function queryValue(string $sql, ...$params): string
    {
        $result = '';

        try {
            $result = $this->queryValueThrow($sql, ...$params);
        } catch (Exception) {}

        return $result;
    }

    /**
     * @throws Exception
     */
    public function queryValueThrow(string $sql, ...$params): string
    {
        $statement = $this->querySql($sql, $params);

        return (string) $statement->fetchColumn();
    }

    /**
     * @throws Exception
     */
    public function queryRow(string $sql, ...$params): array
    {
        $result = [];

        try {
            $statement = $this->querySql($sql, $params);

            $result = $statement->fetch();
        } catch (PDOException $e) {
            if ($this->throwException) {
                throw $e;
            }
        }

        return $result;
    }

    public function queryColumn(string $sql, ...$params): array
    {
        $dbAll = $this->queryAll($sql, ...$params);

        $result = [];
        foreach ($dbAll as $row) {
            foreach ($row as $value) {
                $result[] = $value;
            }
        }

        return $result;
    }

    public function queryAll(string $sql, ...$params): array
    {
        try {
            $result = $this->queryAllThrow($sql, ...$params);
        } catch (Exception) {
            $result = [];
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    public function queryAllThrow(string $sql, ...$params): array
    {
        $statement = $this->querySql($sql, $params);

        return $statement->fetchAll();
    }

    private function getOnUpdate(array $columns): string
    {
        $pairs = [];
        foreach ($columns as $column) {
            $pairs[] = sprintf('`%s` = VALUES(`%s`)', $column, $column);
        }

        return implode(', ', $pairs);
    }

    /**
     * @throws PDOException|Exception
     */
    private function querySql(string $sql, array $params): PDOStatement
    {
        $statement = $this->getConnection()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @throws Exception
     */
    protected function getConnection(bool $forceReload = false): PDO
    {
        if ($forceReload || !isset($this->pdo)) {
            $this->initPDO();
        }

        return $this->pdo;
    }


    /**
     * @throws Exception
     */
    protected function initPDO(): void
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];

        try {
            $this->pdo = new PDO($this->buildDsn(), $this->user, $this->password, $options);
        } catch (PDOException $e) {
            throw new Exception(sprintf('Database connection failed due: %s', $e->getMessage()));
        }
    }

    /**
     * @return string
     */
    protected function buildDsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->name,
            $this->charset
        );
    }

    protected function backtickArray(array $array): array
    {
        return array_map(function ($value) {
            return '`' . $value . '`';
        }, $array);
    }

    protected function getQuestionMarks(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
