<?php

declare(strict_types=1);

/**
 * Seed the local docker mariadb:11 (fledge-fiber docker-compose, port 13307)
 * with three tables shaped like the host-2 benchmark tables:
 *
 *   tk_raw_json             char(36) uuid PK, ~1.6 KB JSON per row
 *   koop_document_metadata  bigint PK, ~50 B per row
 *   feed_items              bigint PK, ~312 B per row
 *
 * Usage: php seed_local_mariadb.php [--host=127.0.0.1] [--port=13307]
 *        [--user=fledge] [--password=fledge] [--database=fledge_test]
 *
 * Drops and recreates the three tables. Refuses anything that is not a
 * loopback MariaDB on the docker port, so it can never touch a real server.
 * Rows come from MariaDB's Sequence engine (seq_1_to_N), one INSERT ... SELECT
 * per table.
 */

const ROWS = [
    'tk_raw_json' => 20_000,
    'koop_document_metadata' => 200_000,
    'feed_items' => 100_000,
];

$opt = ['host' => '127.0.0.1', 'port' => '13307', 'user' => 'fledge', 'password' => 'fledge', 'database' => 'fledge_test'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(host|port|user|password|database)=(.*)$/', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    }
}

if (! in_array($opt['host'], ['127.0.0.1', '::1'], true) || $opt['port'] !== '13307') {
    fwrite(STDERR, "ERROR: refusing to seed {$opt['host']}:{$opt['port']}; only the local docker mariadb on 127.0.0.1:13307\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$opt['host']};port={$opt['port']};dbname={$opt['database']};charset=utf8mb4",
    $opt['user'],
    $opt['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$version = (string) $pdo->query('select version()')->fetchColumn();
if (! str_contains($version, 'MariaDB')) {
    fwrite(STDERR, "ERROR: server is not MariaDB ({$version})\n");
    exit(1);
}
echo "server {$version}, database {$opt['database']}\n";

// Uniformly distributed uuid-shaped keys (md5 based, deterministic), so a random
// hex prefix lands somewhere inside the key space like real v4 uuids.
$uuid = "insert(insert(insert(insert(md5(concat('tk', seq)), 9, 0, '-'), 14, 0, '-'), 19, 0, '-'), 24, 0, '-')";

$tables = [
    'tk_raw_json' => [
        'create table tk_raw_json (
            id char(36) not null primary key,
            source varchar(32) not null,
            data longtext not null check (json_valid(data)),
            fetched_at timestamp not null default current_timestamp
        ) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci',
        "insert into tk_raw_json (id, source, data, fetched_at)
            select {$uuid}, 'tweedekamer',
                json_object('id', seq, 'type', 'Kamerstuk', 'title', concat('Kamerstuk nummer ', seq),
                    'date', date_add('2020-01-01', interval seq minute), 'body', repeat(md5(seq), 46)),
                date_add('2024-01-01', interval seq second)
            from seq_1_to_" . ROWS['tk_raw_json'],
    ],
    'koop_document_metadata' => [
        'create table koop_document_metadata (
            id bigint unsigned not null auto_increment primary key,
            document_id bigint unsigned not null,
            `key` varchar(32) not null,
            value varchar(64) not null,
            key koop_document_metadata_document_id_index (document_id)
        ) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci',
        "insert into koop_document_metadata (document_id, `key`, value)
            select seq div 7, elt(1 + seq % 4, 'dcterms:type', 'overheid:authority', 'dcterms:issued', 'dcterms:language'),
                concat('value-', seq)
            from seq_1_to_" . ROWS['koop_document_metadata'],
    ],
    'feed_items' => [
        'create table feed_items (
            id bigint unsigned not null auto_increment primary key,
            feed_id int unsigned not null,
            guid char(40) not null,
            title varchar(160) not null,
            url varchar(255) not null,
            summary varchar(255) not null,
            published_at datetime not null,
            created_at timestamp not null default current_timestamp,
            key feed_items_feed_id_index (feed_id)
        ) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci',
        "insert into feed_items (feed_id, guid, title, url, summary, published_at, created_at)
            select seq % 50, sha1(seq),
                concat('Nieuwsbericht ', seq, ' over de begroting en de stand van zaken in de regio'),
                concat('https://www.example.nl/nieuws/', date_format(date_add('2020-01-01', interval seq minute), '%Y/%m/%d'), '/bericht-', seq),
                concat('Samenvatting van bericht ', seq, ': korte tekst die de inhoud van het item beschrijft.'),
                date_add('2020-01-01', interval seq minute),
                date_add('2024-01-01', interval seq second)
            from seq_1_to_" . ROWS['feed_items'],
    ],
];

foreach ($tables as $table => [$create, $insert]) {
    $t0 = hrtime(true);
    $pdo->exec("drop table if exists `{$table}`");
    $pdo->exec($create);
    $pdo->exec($insert);
    $pdo->query("analyze table `{$table}`")->fetchAll();
    $count = (int) $pdo->query("select count(*) from `{$table}`")->fetchColumn();
    $avg = (int) $pdo->query("select avg_row_length from information_schema.tables where table_schema = database() and table_name = '{$table}'")->fetchColumn();
    printf("%-24s %8d rows, avg_row_length %5d B, %.1fs\n", $table, $count, $avg, (hrtime(true) - $t0) / 1e9);
}
