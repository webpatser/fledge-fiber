<?php

namespace Fledge\Fiber\Database\Pdo;

use Fledge\Async\CancelledException;
use Fledge\Async\CompositeException;
use Fledge\Async\Database\SqlConnectionException;
use Fledge\Async\Database\SqlQueryError;
use Fledge\Async\Database\SqlTransactionError;
use Fledge\Async\Stream\ConnectException;
use Fledge\Async\Stream\StreamException;
use Fledge\Async\Stream\TlsException;
use Fledge\Async\TimeoutException;
use PDOException;
use Throwable;

/**
 * PDOException shaped like the ones pdo_mysql and pdo_pgsql throw.
 *
 * Illuminate\Database only recognises driver errors that look like PDO's:
 * MySqlConnection matches "Integrity constraint violation: 1062" in the message,
 * PostgresConnection compares getCode() with "23505", the concurrency detector
 * checks for code "40001", and LostConnectionDetector matches the exact text
 * libmysql/mysqlnd and libpq produce. fromThrowable() turns everything the
 * async drivers can raise into that shape, so none of it leaks past the shims.
 */
class FledgePdoException extends PDOException
{
    /**
     * PDO's SQLSTATE descriptions (ext/pdo/pdo_sqlstate.c). PDO looks up the exact
     * state and prints "<<Unknown error>>" when it is not listed.
     */
    private const array DESCRIPTIONS = [
        '00000' => 'No error', '01000' => 'Warning', '01001' => 'Cursor operation conflict',
        '01002' => 'Disconnect error', '01003' => 'NULL value eliminated in set function',
        '01004' => 'String data, right truncated', '01006' => 'Privilege not revoked',
        '01007' => 'Privilege not granted', '01008' => 'Implicit zero bit padding',
        '0100C' => 'Dynamic result sets returned', '01P01' => 'Deprecated feature',
        '01S00' => 'Invalid connection string attribute', '01S01' => 'Error in row',
        '01S02' => 'Option value changed',
        '01S06' => 'Attempt to fetch before the result set returned the first rowset',
        '01S07' => 'Fractional truncation', '01S08' => 'Error saving File DSN', '01S09' => 'Invalid keyword',
        '02000' => 'No data', '02001' => 'No additional dynamic result sets returned',
        '03000' => 'Sql statement not yet complete', '07002' => 'COUNT field incorrect',
        '07005' => 'Prepared statement not a cursor-specification',
        '07006' => 'Restricted data type attribute violation', '07009' => 'Invalid descriptor index',
        '07S01' => 'Invalid use of default parameter', '08000' => 'Connection exception',
        '08001' => 'Client unable to establish connection', '08002' => 'Connection name in use',
        '08003' => 'Connection does not exist', '08004' => 'Server rejected the connection',
        '08006' => 'Connection failure', '08007' => 'Connection failure during transaction',
        '08S01' => 'Communication link failure', '09000' => 'Triggered action exception',
        '0A000' => 'Feature not supported', '0B000' => 'Invalid transaction initiation',
        '0F000' => 'Locator exception', '0F001' => 'Invalid locator specification',
        '0L000' => 'Invalid grantor', '0LP01' => 'Invalid grant operation',
        '0P000' => 'Invalid role specification', '21000' => 'Cardinality violation',
        '21S01' => 'Insert value list does not match column list',
        '21S02' => 'Degree of derived table does not match column list', '22000' => 'Data exception',
        '22001' => 'String data, right truncated', '22002' => 'Indicator variable required but not supplied',
        '22003' => 'Numeric value out of range', '22004' => 'Null value not allowed',
        '22005' => 'Error in assignment', '22007' => 'Invalid datetime format',
        '22008' => 'Datetime field overflow', '22009' => 'Invalid time zone displacement value',
        '2200B' => 'Escape character conflict', '2200C' => 'Invalid use of escape character',
        '2200D' => 'Invalid escape octet', '2200F' => 'Zero length character string',
        '2200G' => 'Most specific type mismatch', '22010' => 'Invalid indicator parameter value',
        '22011' => 'Substring error', '22012' => 'Division by zero', '22015' => 'Interval field overflow',
        '22018' => 'Invalid character value for cast specification', '22019' => 'Invalid escape character',
        '2201B' => 'Invalid regular expression', '2201E' => 'Invalid argument for logarithm',
        '2201F' => 'Invalid argument for power function',
        '2201G' => 'Invalid argument for width bucket function', '22020' => 'Invalid limit value',
        '22021' => 'Character not in repertoire', '22022' => 'Indicator overflow',
        '22023' => 'Invalid parameter value', '22024' => 'Unterminated c string',
        '22025' => 'Invalid escape sequence', '22026' => 'String data, length mismatch',
        '22027' => 'Trim error', '2202E' => 'Array subscript error', '22P01' => 'Floating point exception',
        '22P02' => 'Invalid text representation', '22P03' => 'Invalid binary representation',
        '22P04' => 'Bad copy file format', '22P05' => 'Untranslatable character',
        '23000' => 'Integrity constraint violation', '23001' => 'Restrict violation',
        '23502' => 'Not null violation', '23503' => 'Foreign key violation', '23505' => 'Unique violation',
        '23514' => 'Check violation', '24000' => 'Invalid cursor state', '25000' => 'Invalid transaction state',
        '25001' => 'Active sql transaction', '25002' => 'Branch transaction already active',
        '25003' => 'Inappropriate access mode for branch transaction',
        '25004' => 'Inappropriate isolation level for branch transaction',
        '25005' => 'No active sql transaction for branch transaction', '25006' => 'Read only sql transaction',
        '25007' => 'Schema and data statement mixing not supported',
        '25008' => 'Held cursor requires same isolation level', '25P01' => 'No active sql transaction',
        '25P02' => 'In failed sql transaction', '25S01' => 'Transaction state',
        '25S02' => 'Transaction is still active', '25S03' => 'Transaction is rolled back',
        '26000' => 'Invalid sql statement name', '27000' => 'Triggered data change violation',
        '28000' => 'Invalid authorization specification',
        '2B000' => 'Dependent privilege descriptors still exist', '2BP01' => 'Dependent objects still exist',
        '2D000' => 'Invalid transaction termination', '2F000' => 'Sql routine exception',
        '2F002' => 'Modifying sql data not permitted', '2F003' => 'Prohibited sql statement attempted',
        '2F004' => 'Reading sql data not permitted', '2F005' => 'Function executed no return statement',
        '34000' => 'Invalid cursor name', '38000' => 'External routine exception',
        '38001' => 'Containing sql not permitted', '38002' => 'Modifying sql data not permitted',
        '38003' => 'Prohibited sql statement attempted', '38004' => 'Reading sql data not permitted',
        '39000' => 'External routine invocation exception', '39001' => 'Invalid sqlstate returned',
        '39004' => 'Null value not allowed', '39P01' => 'Trigger protocol violated',
        '39P02' => 'Srf protocol violated', '3B000' => 'Savepoint exception',
        '3B001' => 'Invalid savepoint specification', '3C000' => 'Duplicate cursor name',
        '3D000' => 'Invalid catalog name', '3F000' => 'Invalid schema name', '40000' => 'Transaction rollback',
        '40001' => 'Serialization failure', '40002' => 'Transaction integrity constraint violation',
        '40003' => 'Statement completion unknown', '40P01' => 'Deadlock detected',
        '42000' => 'Syntax error or access violation', '42501' => 'Insufficient privilege',
        '42601' => 'Syntax error', '42602' => 'Invalid name', '42611' => 'Invalid column definition',
        '42622' => 'Name too long', '42701' => 'Duplicate column', '42702' => 'Ambiguous column',
        '42703' => 'Undefined column', '42704' => 'Undefined object', '42710' => 'Duplicate object',
        '42712' => 'Duplicate alias', '42723' => 'Duplicate function', '42725' => 'Ambiguous function',
        '42803' => 'Grouping error', '42804' => 'Datatype mismatch', '42809' => 'Wrong object type',
        '42830' => 'Invalid foreign key', '42846' => 'Cannot coerce', '42883' => 'Undefined function',
        '42939' => 'Reserved name', '42P01' => 'Undefined table', '42P02' => 'Undefined parameter',
        '42P03' => 'Duplicate cursor', '42P04' => 'Duplicate database',
        '42P05' => 'Duplicate prepared statement', '42P06' => 'Duplicate schema', '42P07' => 'Duplicate table',
        '42P08' => 'Ambiguous parameter', '42P09' => 'Ambiguous alias', '42P10' => 'Invalid column reference',
        '42P11' => 'Invalid cursor definition', '42P12' => 'Invalid database definition',
        '42P13' => 'Invalid function definition', '42P14' => 'Invalid prepared statement definition',
        '42P15' => 'Invalid schema definition', '42P16' => 'Invalid table definition',
        '42P17' => 'Invalid object definition', '42P18' => 'Indeterminate datatype',
        '42S01' => 'Base table or view already exists', '42S02' => 'Base table or view not found',
        '42S11' => 'Index already exists', '42S12' => 'Index not found', '42S21' => 'Column already exists',
        '42S22' => 'Column not found', '44000' => 'WITH CHECK OPTION violation',
        '53000' => 'Insufficient resources', '53100' => 'Disk full', '53200' => 'Out of memory',
        '53300' => 'Too many connections', '54000' => 'Program limit exceeded',
        '54001' => 'Statement too complex', '54011' => 'Too many columns', '54023' => 'Too many arguments',
        '55000' => 'Object not in prerequisite state', '55006' => 'Object in use',
        '55P02' => 'Cant change runtime param', '55P03' => 'Lock not available',
        '57000' => 'Operator intervention', '57014' => 'Query canceled', '57P01' => 'Admin shutdown',
        '57P02' => 'Crash shutdown', '57P03' => 'Cannot connect now', '58030' => 'Io error',
        '58P01' => 'Undefined file', '58P02' => 'Duplicate file', 'F0000' => 'Config file error',
        'F0001' => 'Lock file exists', 'HY000' => 'General error', 'HY001' => 'Memory allocation error',
        'HY003' => 'Invalid application buffer type', 'HY004' => 'Invalid SQL data type',
        'HY007' => 'Associated statement is not prepared', 'HY008' => 'Operation canceled',
        'HY009' => 'Invalid use of null pointer', 'HY010' => 'Function sequence error',
        'HY011' => 'Attribute cannot be set now', 'HY012' => 'Invalid transaction operation code',
        'HY013' => 'Memory management error', 'HY014' => 'Limit on the number of handles exceeded',
        'HY015' => 'No cursor name available', 'HY016' => 'Cannot modify an implementation row descriptor',
        'HY017' => 'Invalid use of an automatically allocated descriptor handle',
        'HY018' => 'Server declined cancel request',
        'HY019' => 'Non-character and non-binary data sent in pieces',
        'HY020' => 'Attempt to concatenate a null value', 'HY021' => 'Inconsistent descriptor information',
        'HY024' => 'Invalid attribute value', 'HY090' => 'Invalid string or buffer length',
        'HY091' => 'Invalid descriptor field identifier', 'HY092' => 'Invalid attribute/option identifier',
        'HY093' => 'Invalid parameter number', 'HY095' => 'Function type out of range',
        'HY096' => 'Invalid information type', 'HY097' => 'Column type out of range',
        'HY098' => 'Scope type out of range', 'HY099' => 'Nullable type out of range',
        'HY100' => 'Uniqueness option type out of range', 'HY101' => 'Accuracy option type out of range',
        'HY103' => 'Invalid retrieval code', 'HY104' => 'Invalid precision or scale value',
        'HY105' => 'Invalid parameter type', 'HY106' => 'Fetch type out of range',
        'HY107' => 'Row value out of range', 'HY109' => 'Invalid cursor position',
        'HY110' => 'Invalid driver completion', 'HY111' => 'Invalid bookmark value',
        'HYC00' => 'Optional feature not implemented', 'HYT00' => 'Timeout expired',
        'HYT01' => 'Connection timeout expired', 'IM001' => 'Driver does not support this function',
        'IM002' => 'Data source name not found and no default driver specified',
        'IM003' => 'Specified driver could not be loaded',
        'IM004' => "Driver's SQLAllocHandle on SQL_HANDLE_ENV failed",
        'IM005' => "Driver's SQLAllocHandle on SQL_HANDLE_DBC failed",
        'IM006' => "Driver's SQLSetConnectAttr failed",
        'IM007' => 'No data source or driver specified; dialog prohibited', 'IM008' => 'Dialog failed',
        'IM009' => 'Unable to load translation DLL', 'IM010' => 'Data source name too long',
        'IM011' => 'Driver name too long', 'IM012' => 'DRIVER keyword syntax error',
        'IM013' => 'Trace file error', 'IM014' => 'Invalid name of File DSN',
        'IM015' => 'Corrupt file data source', 'P0000' => 'Plpgsql error', 'P0001' => 'Raise exception',
        'XX000' => 'Internal error', 'XX001' => 'Data corrupted', 'XX002' => 'Index corrupted',
    ];

    /**
     * pdo_pgsql reports the libpq result status as the native code: PGRES_FATAL_ERROR.
     */
    public const int PGSQL_NATIVE_CODE = 7;

    /**
     * mysqlnd client error codes (CR_*) used for client-side failures.
     */
    public const int CR_CONNECTION_ERROR = 2002;

    public const int CR_SERVER_GONE_ERROR = 2006;

    public const int CR_MALFORMED_PACKET = 2027;

    /**
     * libpq texts LostConnectionDetector knows, for a dead socket and a loss mid-query.
     */
    public const string PGSQL_NO_CONNECTION = 'no connection to the server';

    public const string PGSQL_SERVER_CLOSED = "server closed the connection unexpectedly\n\tThis probably means the server terminated abnormally\n\tbefore or while processing the request.";

    /**
     * Plain \Error messages the drivers raise for a connection that is already gone.
     */
    private const array CLOSED_ERROR_MESSAGES = [
        'The connection has been closed',
        'The connection to the database has been closed',
        'The pool has been closed',
        'The statement has been closed',
    ];

    /**
     * Prefixes of plain \Error messages the drivers raise for bad parameter binding.
     */
    private const string PARAMETER_ERROR_PATTERN = '/^(Parameter \S+ (missing|is not defined)|Named parameter|Value for (unnamed|named) parameter|Cannot mix unnamed|Numbered placeholders must be sequential)/';

    /**
     * @param  int|string  $code  The SQLSTATE string for statement errors, the native int for connect errors, 0 for PDO's own errors
     * @param  array<int, int|string|null>|null  $errorInfo
     */
    public function __construct(string $message, int|string $code, ?array $errorInfo, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        // PDO exposes the SQLSTATE string as the exception code, which the int-only parent constructor rejects.
        $this->code = $code;
        $this->errorInfo = $errorInfo;
    }

    /**
     * Translate anything the async drivers raise into the exception pdo_mysql/pdo_pgsql would throw.
     *
     * PDOExceptions pass through. Programming errors (TypeError, ValueError, and \Error that no
     * driver condition explains) are returned untouched so the caller rethrows them as they are.
     *
     * @param  string  $driver  The PDO driver name: "mysql" (MySQL and MariaDB) or "pgsql"
     */
    public static function fromThrowable(Throwable $e, string $driver): Throwable
    {
        $pgsql = $driver === 'pgsql';

        if ($e instanceof PDOException) {
            return $e;
        }

        if ($e instanceof SqlQueryError) {
            return $pgsql ? self::fromPostgresQueryError($e) : self::fromQueryError($e);
        }

        if ($e instanceof SqlTransactionError) {
            return self::pdoError('There is no active transaction', $e);
        }

        if ($e instanceof \Error) {
            if ($e::class !== \Error::class) {
                return $e;
            }

            if (in_array($e->getMessage(), self::CLOSED_ERROR_MESSAGES, true)) {
                return self::connectionLost($e, $pgsql, midQuery: false);
            }

            if (preg_match(self::PARAMETER_ERROR_PATTERN, $e->getMessage())) {
                return self::implError('HY093', 'number of bound variables does not match number of tokens', $e);
            }

            return $e;
        }

        if (($connectFailure = self::connectFailure($e)) !== null) {
            return self::fromConnectFailure($connectFailure, $e, $pgsql);
        }

        if (str_starts_with($e->getMessage(), 'Failed to initialize database session: ') && $e->getPrevious() !== null) {
            $mapped = self::fromThrowable($e->getPrevious(), $driver);

            return $mapped instanceof self ? $mapped->withPrevious($e) : $mapped;
        }

        if ($e instanceof CancelledException || $e instanceof TimeoutException) {
            return $pgsql
                ? self::driverError('57014', self::PGSQL_NATIVE_CODE, 'ERROR:  canceling statement due to user request', $e)
                : self::driverError('HY000', self::CR_SERVER_GONE_ERROR, 'MySQL server has gone away', $e);
        }

        if ($e instanceof SqlConnectionException || $e instanceof StreamException || self::isClosedMessage($e->getMessage())) {
            return self::connectionLost($e, $pgsql, midQuery: self::lostMidQuery($e));
        }

        // Protocol, parse and result errors (SqlException and the rest): a general error, as the C drivers report them.
        return $pgsql
            ? self::driverError('HY000', self::PGSQL_NATIVE_CODE, rtrim($e->getMessage()), $e)
            : self::driverError('HY000', is_int($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : self::CR_MALFORMED_PACKET, $e->getMessage(), $e);
    }

    /**
     * Translate a Fledge Async query error into a PDO-shaped exception.
     *
     * @param  int|null  $driverCode  Overrides the native code in the message and errorInfo[1]
     *                                (pdo_pgsql reports the libpq result status, not a server error number).
     */
    public static function fromQueryError(SqlQueryError $error, ?int $driverCode = null): self
    {
        $sqlState = $error->getSqlState() ?? 'HY000';
        $code = $driverCode ?? $error->getErrorCode();

        return self::driverError($sqlState, $code, rtrim($error->getServerMessage()), $error);
    }

    /**
     * pdo_pgsql reports PQerrorMessage(), the connection's message. When the server ends the
     * session (57P01 pg_terminate_backend or shutdown, 57P02 crash, 57P03 not accepting
     * connections), libpq appends its connection-loss text to the FATAL, and that text is
     * what LostConnectionDetector matches.
     */
    private static function fromPostgresQueryError(SqlQueryError $error): self
    {
        if (! in_array($error->getSqlState(), ['57P01', '57P02', '57P03'], true)) {
            return self::fromQueryError($error, self::PGSQL_NATIVE_CODE);
        }

        // libpq's result for a lost connection carries no SQLSTATE, so pdo_pgsql falls back to HY000.
        return self::driverError('HY000', self::PGSQL_NATIVE_CODE, self::withServerClosed($error->getServerMessage()), $error);
    }

    /**
     * PDO's own error for state misuse (no driver involved): int code 0 and no errorInfo,
     * like "There is no active transaction".
     */
    public static function pdoError(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 0, null, $previous);
    }

    /**
     * PDO's human-readable description of a SQLSTATE.
     */
    public static function describe(string $sqlState): string
    {
        return self::DESCRIPTIONS[$sqlState] ?? '<<Unknown error>>';
    }

    /**
     * A statement-time driver error: "SQLSTATE[x]: Description: code message", code = SQLSTATE.
     */
    private static function driverError(string $sqlState, int $code, string $message, Throwable $previous): self
    {
        return new self(
            sprintf('SQLSTATE[%s]: %s: %d %s', $sqlState, self::describe($sqlState), $code, $message),
            $sqlState,
            [$sqlState, $code, $message],
            $previous,
        );
    }

    /**
     * An error PDO raises itself (pdo_raise_impl_error): "SQLSTATE[x]: Description: detail".
     */
    public static function implError(string $sqlState, string $detail, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('SQLSTATE[%s]: %s: %s', $sqlState, self::describe($sqlState), $detail),
            $sqlState,
            [$sqlState, 0],
            $previous,
        );
    }

    /**
     * A failure while connecting (pdo_throw_exception): "SQLSTATE[x] [code] message", int code.
     */
    private static function connectError(string $sqlState, int $code, string $message, Throwable $previous): self
    {
        return new self(
            sprintf('SQLSTATE[%s] [%d] %s', $sqlState, $code, $message),
            $code,
            [$sqlState, $code, $message],
            $previous,
        );
    }

    /**
     * The error the C drivers raise when the server connection is gone.
     */
    private static function connectionLost(Throwable $e, bool $pgsql, bool $midQuery): self
    {
        // mysqlnd reports 2006 for a killed or dropped connection, idle or mid-query alike.
        if (! $pgsql) {
            return self::driverError('HY000', self::CR_SERVER_GONE_ERROR, 'MySQL server has gone away', $e);
        }

        // libpq's lost-connection results carry no SQLSTATE, so pdo_pgsql reports HY000. When the
        // server sent a FATAL before closing, the driver keeps it and libpq's text follows it.
        $message = rtrim($e->getMessage());

        $text = match (true) {
            str_starts_with($message, 'FATAL:') => self::withServerClosed($message),
            str_contains($message, 'server closed the connection unexpectedly') => $message,
            $midQuery => self::PGSQL_SERVER_CLOSED,
            default => self::PGSQL_NO_CONNECTION,
        };

        return self::driverError('HY000', self::PGSQL_NATIVE_CODE, $text, $e);
    }

    /**
     * A server FATAL followed by the text libpq appends when the server then closes the socket.
     */
    private static function withServerClosed(string $fatal): string
    {
        $fatal = rtrim($fatal);

        return str_contains($fatal, 'server closed the connection unexpectedly')
            ? $fatal
            : $fatal."\n".self::PGSQL_SERVER_CLOSED;
    }

    /**
     * Find the innermost cause of a failed connection attempt, or null when the error is not one.
     *
     * Connection attempts surface as RetrySqlConnector's "after N tries" wrapper (around a
     * CompositeException of the attempts), the MySQL socket wrapper, the pecl-pq wrapper, the
     * MySQL handshake error, or a ConnectException/TlsException anywhere in the chain.
     */
    private static function connectFailure(Throwable $e): ?Throwable
    {
        $message = $e->getMessage();

        if (str_starts_with($message, 'Could not connect to database server at ')) {
            $previous = $e->getPrevious();

            if ($previous instanceof CompositeException) {
                $reasons = $previous->getReasons();
                $previous = end($reasons) ?: null;
            }

            return $previous === null ? $e : (self::connectFailure($previous) ?? $previous);
        }

        if (str_starts_with($message, 'Connecting to the MySQL server failed: ')
            || str_starts_with($message, 'Could not connect to PostgreSQL server')) {
            return $e->getPrevious() ?? $e;
        }

        if (preg_match('#^Could not connect to (tcp|unix)://#', $message)) {
            return $e;
        }

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectException || $cause instanceof TlsException) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * Shape a connection failure like the PDO constructor does.
     */
    private static function fromConnectFailure(Throwable $cause, Throwable $original, bool $pgsql): self
    {
        $message = rtrim($cause->getMessage());

        if ($pgsql) {
            // pdo_pgsql reports every connection failure as 08006 with libpq's own text.
            return self::connectError('08006', self::PGSQL_NATIVE_CODE, $message, $original);
        }

        // Handshake ERR packet: "Could not connect to tcp://host:port: #28000Access denied ...".
        if (preg_match('#^Could not connect to \S+: (?:\#[0-9A-Z]{5})?(.*)$#s', $message, $m)) {
            $serverMessage = $m[1];

            return self::connectError('HY000', self::mysqlHandshakeCode($cause, $serverMessage), $serverMessage, $original);
        }

        if ($message === 'Connection closed unexpectedly') {
            // mysqlnd (unlike libmysqlclient) reports a dropped handshake as 2006.
            return self::connectError('HY000', self::CR_SERVER_GONE_ERROR, 'MySQL server has gone away', $original);
        }

        if ($cause instanceof TlsException) {
            return self::connectError('HY000', self::CR_CONNECTION_ERROR, 'Cannot connect to MySQL using SSL', $original);
        }

        return self::connectError('HY000', self::CR_CONNECTION_ERROR, self::mysqlSocketMessage($cause), $original);
    }

    /**
     * The server error number of a MySQL handshake failure: the driver passes it as the
     * exception code. Parsing the text is the fallback for a code of 0.
     */
    private static function mysqlHandshakeCode(Throwable $cause, string $message): int
    {
        if (is_int($cause->getCode()) && $cause->getCode() > 0) {
            return $cause->getCode();
        }

        return match (true) {
            str_starts_with($message, 'Access denied for user') && str_contains($message, 'to database') => 1044,
            str_starts_with($message, 'Access denied for user') => 1045,
            str_starts_with($message, 'Unknown database') => 1049,
            str_starts_with($message, 'Too many connections') => 1040,
            str_contains($message, 'is not allowed to connect to this') => 1130,
            str_contains($message, 'is blocked because of many connection errors') => 1129,
            default => 2000,
        };
    }

    /**
     * mysqlnd's text for a socket that could not be opened (CR_CONNECTION_ERROR).
     */
    private static function mysqlSocketMessage(Throwable $cause): string
    {
        $message = $cause->getMessage();

        return match (true) {
            str_contains($message, 'refused') => 'Connection refused',
            str_contains($message, 'timeout exceeded') || str_contains($message, 'timed out') => 'Connection timed out',
            str_contains($message, 'No such file or directory') => 'No such file or directory',
            str_contains($message, 'DNS resolution for') => preg_replace('/^DNS resolution for (\S+) failed: .*$/s', 'php_network_getaddresses: getaddrinfo for $1 failed: Name or service not known', $message),
            default => $message,
        };
    }

    /**
     * Whether the connection dropped while a query was in flight (as opposed to being dead beforehand).
     */
    private static function lostMidQuery(Throwable $e): bool
    {
        return in_array($e->getMessage(), [
            'Connection closed unexpectedly',
            'The connection closed during the operation',
            'Connection closed after receiving an unexpected error packet',
        ], true) || str_contains($e->getMessage(), 'server closed the connection unexpectedly');
    }

    /**
     * Whether a driver SqlException describes a connection or pool that is gone.
     */
    private static function isClosedMessage(string $message): bool
    {
        return in_array($message, [
            'The statement has been closed or the connection went away',
            'The statement has been closed or the connection pool has been closed',
            'Pool closed before an active connection could be obtained',
            'The statement has been closed',
        ], true);
    }

    /**
     * Re-wrap with an outer driver exception as previous, keeping the mapped shape.
     */
    private function withPrevious(Throwable $previous): self
    {
        return new self($this->getMessage(), $this->code, $this->errorInfo, $previous);
    }
}
