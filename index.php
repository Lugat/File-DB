<?php
declare(strict_types=1);

require_once 'src/Collection.php';
require_once 'src/Condition.php';
require_once 'src/QueryBuilder.php';

ini_set('memory_limit', '1G');

/* === Sample data === */

$people = [
  ['name' => 'Alice', 'age' => 30, 'status' => 'active'],
  ['name' => 'Bob', 'age' => 25, 'status' => 'inactive'],
  ['name' => 'Charlie', 'age' => 30, 'status' => 'active'],
];
$shopUsers = [
  ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
  ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
  ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
];
$shopOrders = [
  ['order_id' => 100, 'user_id' => 1, 'amount' => 250],
  ['order_id' => 101, 'user_id' => 2, 'amount' => 300],
  ['order_id' => 102, 'user_id' => 1, 'amount' => 150],
  ['order_id' => 103, 'user_id' => 3, 'amount' => 400],
];
$bigUsers = __DIR__.'/tests/data/users.json';
$bigOrders = __DIR__.'/tests/data/orders.json';

/*
 * Demos: 'sources' is shown as the original data (array or file path),
 * 'query' receives the loaded sources. The displayed query code is the function body.
 */
$demos = [
  'condition' => [
    'title'   => 'Condition with andWhere',
    'info'    => 'Condition as string and as array, combined.',
    'sources' => ['people' => $people],
    'query'   => function (array $src) {
      $condition = (new Condition())
        ->where('age = 30')
        ->andWhere(['status' => 'active']);

      return (new QueryBuilder())
        ->from($src['people'])
        ->where($condition)
        ->execute();
    },
  ],
  'join-array' => [
    'title'   => 'Join with array condition',
    'info'    => 'Join users × orders: one result row per order, Alice appears with both orders.',
    'sources' => ['users' => $shopUsers, 'orders' => $shopOrders],
    'query'   => function (array $src) {
      return (new QueryBuilder())
        ->select('user, order.order_id, order.amount')
        ->from($src['users'], 'user')
        ->join($src['orders'], 'order', ['user.id' => 'order.user_id'])
        ->where('user.name = Alice OR user.name = Bob')
        ->execute();
    },
  ],
  'join-sum' => [
    'title'   => 'Join + SUM / GROUP BY',
    'info'    => 'Sum and count of orders per user, sorted by revenue.',
    'sources' => ['users' => $shopUsers, 'orders' => $shopOrders],
    'query'   => function (array $src) {
      return (new QueryBuilder())
        ->select('user.name, SUM(order.amount) AS total, COUNT(*) AS orders')
        ->from($src['users'], 'user')
        ->join($src['orders'], 'order', 'user.id = order.user_id')
        ->groupBy('user.id')
        ->orderBy('total', 'DESC')
        ->execute();
    },
  ],
  'nested' => [
    'title'   => 'Nested fields',
    'info'    => 'Dot notation on users.json.',
    'sources' => ['users.json' => __DIR__.'/users.json'],
    'query'   => function (array $src) {
      return (new QueryBuilder())
        ->select('id, firstName, age, company.address.country')
        ->from($src['users.json'])
        ->where('age < 30')
        ->andWhere('company.address.country = "United States"')
        ->execute();
    },
  ],
  'parens-like' => [
    'title'   => 'Parentheses, LIKE, IN',
    'info'    => 'Grouped OR condition, LIKE with % and an IN list.',
    'sources' => ['users.json' => __DIR__.'/users.json'],
    'query'   => function (array $src) {
      return (new QueryBuilder())
        ->select('firstName, lastName, gender, bloodGroup, hair.color')
        ->from($src['users.json'])
        ->where("(gender = female OR hair.color in (Brown, Black)) AND lastName like '%son'")
        ->execute();
    },
  ],
  'order-limit' => [
    'title'   => 'Sort, limit, offset',
    'info'    => 'The three oldest users after the oldest one, ties sorted by name.',
    'sources' => ['users.json' => __DIR__.'/users.json'],
    'query'   => function (array $src) {
      return (new QueryBuilder())
        ->select('firstName, lastName, age')
        ->from($src['users.json'])
        ->orderBy('age', 'DESC')
        ->orderBy('firstName')
        ->offset(1)
        ->limit(3)
        ->execute();
    },
  ],
  'collection-write' => [
    'title'   => 'Write a collection',
    'info'    => 'add/update/delete on a temporary copy of data.json, then reloaded.',
    'sources' => ['data.json' => __DIR__.'/data.json'],
    'query'   => function (array $src) {
      $file = sys_get_temp_dir().'/filedb_demo.json';
      file_put_contents($file, json_encode($src['data.json']));

      $collection = new Collection($file);
      $collection->add(['name' => 'Eve', 'age' => 41, 'status' => 'active']);
      foreach ($collection->select()->where('name = Diana')->execute() as $row) {
        $collection->update($row, ['status' => 'archived'] + $row);
      }
      $collection->delete(fn($row) => ($row['age'] ?? 0) > 40 && 'Eve' !== $row['name']);
      $collection->save();

      $result = (new Collection($file))->getData();
      unlink($file);
      return $result;
    },
  ],
  'big-filter' => [
    'title'   => 'Large: filter 50k users',
    'info'    => 'Requires php tests/generate.php.',
    'sources' => ['users' => $bigUsers],
    'query'   => function (array $src) {
      return (new QueryBuilder())
        ->select('id, firstName, lastName, age, company.name, company.address.city')
        ->from($src['users'])
        ->where('company.name = "Smith and Sons" AND company.address.city in (Berlin, Wien)')
        ->andWhere('age >= 60 AND deletedAt is null')
        ->orderBy('age', 'DESC')
        ->limit(20)
        ->execute();
    },
  ],
  'big-join' => [
    'title'   => 'Large: join users × orders',
    'info'    => 'Hash index join on ~150k orders. Requires php tests/generate.php.',
    'sources' => ['users' => $bigUsers, 'orders' => $bigOrders],
    'query'   => function (array $src) {
      return (new QueryBuilder())
        ->select('user.id, user.firstName, order.order_id, order.amount')
        ->from($src['users'], 'user')
        ->join($src['orders'], 'order', 'user.id = order.user_id')
        ->where('order.status = paid AND order.amount > 990')
        ->orderBy('order.amount', 'DESC')
        ->limit(25)
        ->execute();
    },
  ],
];

/* === Run === */

const PREVIEW_ROWS = 50;

$key = $_GET['demo'] ?? array_key_first($demos);
if (!isset($demos[$key])) {
  $key = array_key_first($demos);
}
$demo = $demos[$key];

// Show the body of the query closure as code
$ref = new ReflectionFunction($demo['query']);
$lines = array_slice(file(__FILE__), $ref->getStartLine(), $ref->getEndLine() - $ref->getStartLine() - 1);
$indent = min(array_map(fn($l) => strlen($l) - strlen(ltrim($l)), array_filter($lines, 'trim')));
$code = implode('', array_map(fn($l) => '' === trim($l) ? "\n" : substr($l, $indent), $lines));

$error = null;
$sources = [];
$result = [];
$ms = 0.0;
try {
  foreach ($demo['sources'] as $name => $source) {
    $sources[$name] = QueryBuilder::loadSource($source);
  }
  $start = microtime(true);
  $result = ($demo['query'])($sources);
  $ms = (microtime(true) - $start) * 1000;
} catch (Throwable $e) {
  $error = $e->getMessage();
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$json = function (array $rows) use ($h): string {
  $shown = array_slice($rows, 0, PREVIEW_ROWS);
  $out = $h(json_encode($shown, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return count($rows) > PREVIEW_ROWS ? $out."\n\n… ".(count($rows) - PREVIEW_ROWS).' more records' : $out;
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>file-db Demos</title>
<style>
  :root { --bg: #f7f7f8; --panel: #fff; --text: #1d1d1f; --muted: #6b6b73; --line: #e2e2e6; --accent: #2b6cde; --code: #f0f1f4; --err: #c62828; }
  @media (prefers-color-scheme: dark) {
    :root { --bg: #151517; --panel: #1e1e21; --text: #e8e8ea; --muted: #9a9aa3; --line: #2e2e33; --accent: #6ea0ff; --code: #26262b; --err: #ff6b6b; }
  }
  * { box-sizing: border-box; }
  body { margin: 0; font: 15px/1.5 system-ui, sans-serif; background: var(--bg); color: var(--text); display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
  nav { border-right: 1px solid var(--line); padding: 20px 12px; background: var(--panel); }
  nav h1 { font-size: 16px; margin: 0 8px 16px; }
  nav a { display: block; padding: 7px 10px; border-radius: 6px; color: var(--text); text-decoration: none; }
  nav a:hover { background: var(--code); }
  nav a.active { background: var(--accent); color: #fff; }
  main { padding: 24px 32px; min-width: 0; }
  h2 { margin: 0 0 4px; }
  .info { color: var(--muted); margin: 0 0 20px; }
  section { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; margin-bottom: 16px; }
  summary, .head { padding: 10px 14px; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; }
  .head { cursor: default; }
  .meta { font-weight: 400; color: var(--muted); }
  pre { margin: 0; padding: 14px; background: var(--code); border-top: 1px solid var(--line); overflow: auto; max-height: 520px; font: 13px/1.45 ui-monospace, Menlo, monospace; border-radius: 0 0 8px 8px; }
  .error { color: var(--err); padding: 14px; border-top: 1px solid var(--line); }
  @media (max-width: 760px) { body { grid-template-columns: 1fr; } nav { border-right: 0; border-bottom: 1px solid var(--line); } main { padding: 16px; } }
</style>
</head>
<body>
<nav>
  <h1>file-db Demos</h1>
  <?php foreach ($demos as $k => $d): ?>
    <a href="?demo=<?= $h($k) ?>" class="<?= $k === $key ? 'active' : '' ?>"><?= $h($d['title']) ?></a>
  <?php endforeach; ?>
</nav>
<main>
  <h2><?= $h($demo['title']) ?></h2>
  <p class="info"><?= $h($demo['info']) ?></p>

  <section>
    <div class="head">Query</div>
    <pre><?= $h($code) ?></pre>
  </section>

  <?php foreach ($sources as $name => $rows): ?>
    <section>
      <details>
        <summary>Original: <?= $h($name) ?> <span class="meta"><?= count($rows) ?> records</span></summary>
        <pre><?= $json($rows) ?></pre>
      </details>
    </section>
  <?php endforeach; ?>

  <section>
    <div class="head">Result
      <span class="meta"><?= $error ? 'Error' : count($result).' records · '.number_format($ms, 1).' ms' ?></span>
    </div>
    <?php if ($error): ?>
      <div class="error"><?= $h($error) ?></div>
    <?php else: ?>
      <pre><?= $json($result) ?></pre>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
