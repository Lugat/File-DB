<?php
declare(strict_types=1);

/**
 * Correctness tests + read/write benchmarks.
 *   php tests/generate.php   (once, creates tests/data/*.json)
 *   php tests/run.php
 */

require_once __DIR__.'/../src/Collection.php';
require_once __DIR__.'/../src/Condition.php';
require_once __DIR__.'/../src/QueryBuilder.php';

ini_set('memory_limit', '2G');
$failed = 0;
function check(string $name, bool $ok): void {
  global $failed;
  echo ($ok ? '  ok   ' : '  FAIL ').$name."\n";
  $failed += $ok ? 0 : 1;
}
function timed(string $label, callable $fn) {
  $t = microtime(true);
  $result = $fn();
  printf("  %-55s %8.1f ms\n", $label, (microtime(true) - $t) * 1000);
  return $result;
}

// ---------------------------------------------------------------------------
echo "Parser (small fixtures)\n";
$d = [
  ['n' => 'Al', 's' => 'b'],
  ['n' => 'Alice', 'age' => 31],
  ['n' => 'Smith and Sons', 'flag' => false],
  ['x' => 1],
  ['n' => 'Bob', 's' => 'a', 'flag' => true],
];
$count = static fn($c) => count((new QueryBuilder())->from($d)->where($c)->execute());
check("LIKE without % is exact", 1 === $count("n like 'Al'"));
check("LIKE with % and _", 2 === $count("n like 'A%'") && 1 === $count("n like 'B_b'"));
check("NOT LIKE", 2 === $count("n not like 'A%'"));
check("AND inside quotes is not split", 1 === $count('n = "Smith and Sons"'));
check("missing field does not satisfy <", 0 === $count('age < 30'));
check("IN with quotes", 2 === $count('n in ("Bob", \'Al\')'));
check("NOT IN", 3 === $count('n not in (Bob, Al)'));
check("parentheses", 1 === $count('(n = Bob OR n = Al) AND s = a'));
check("bool literals", 1 === $count('flag = false') && 1 === $count('flag = true'));
check("IS NULL / IS NOT NULL", 1 === $count('n is null') && 4 === $count('n is not null'));
check("value 'is null' inside a string", 0 === $count('n = "this is null"'));
$r = (new QueryBuilder())->from($d)->where('n = Al')->orWhere((new Condition())->where('n = Bob'))->execute();
check("orWhere with Condition", 2 === count($r));

$shopUsers = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob'], ['id' => 3, 'name' => 'Zoe']];
$shopOrders = [['user_id' => 1, 'amount' => 250], ['user_id' => 2, 'amount' => 300], ['user_id' => 1, 'amount' => 150]];
$join = fn() => (new QueryBuilder())->from($shopUsers, 'user')->join($shopOrders, 'order', 'user.id = order.user_id');
check("join returns all orders per user", 2 === count($join()->where('user.name = Alice')->execute()));
$sum = $join()->select('user.name, SUM(order.amount) AS total, COUNT(*) AS orders')->groupBy('user.id')->orderBy('total', 'DESC')->execute();
check("SUM/COUNT with groupBy", [['user.name' => 'Alice', 'total' => 400, 'orders' => 2], ['user.name' => 'Bob', 'total' => 300, 'orders' => 1]] === $sum);
check("SUM without groupBy = one row", [['total' => 700]] === $join()->select('SUM(order.amount) AS total')->execute());
check("SUM without matches = one row with 0", [['total' => 0]] === $join()->select('SUM(order.amount) AS total')->where('order.amount > 1000')->execute());
check("AVG/MIN/MAX", [['a' => 200, 'mi' => 150, 'ma' => 250]] === $join()->select('AVG(order.amount) AS a, MIN(order.amount) AS mi, MAX(order.amount) AS ma')->where('user.id = 1')->execute());
try { $count('broken'); check("invalid condition throws an exception", false); }
catch (Exception $e) { check("invalid condition throws an exception", true); }

$tmpFile = sys_get_temp_dir().'/filedb_dupes_'.getmypid().'.json';
file_put_contents($tmpFile, json_encode([['a' => 1], ['a' => 1], ['a' => 2]]));
$c = new Collection($tmpFile);
check("identical records are kept", 3 === count($c->getData()));
$c->delete(['a' => 1]);
$c->save();
check("delete removes exactly one record", 2 === count((new Collection($tmpFile))->getData()));
unlink($tmpFile);

// ---------------------------------------------------------------------------
$usersFile = __DIR__.'/data/users.json';
$ordersFile = __DIR__.'/data/orders.json';
if (!file_exists($usersFile) || !file_exists($ordersFile)) {
  echo "\nNo large test data – run `php tests/generate.php` first.\n";
  exit($failed ? 1 : 0);
}

echo "\nRead (".round(filesize($usersFile) / 1048576, 1)." MB users, ".round(filesize($ordersFile) / 1048576, 1)." MB orders)\n";
$users = timed('load Collection (users, primaryKey id)', fn() => new Collection($usersFile, 'id'));
$orders = timed('QueryBuilder::loadSource (orders)', fn() => QueryBuilder::loadSource($ordersFile));
$all = $users->getData();

// Every query result is checked against a plain array_filter as reference
$cases = [
  ['age < 30 AND company.address.country = Germany',
    fn($u) => $u['age'] < 30 && 'Germany' === $u['company']['address']['country']],
  ['(status = active OR status = pending) AND verified = true',
    fn($u) => in_array($u['status'], ['active', 'pending'], true) && $u['verified']],
  ['company.name = "Smith and Sons" AND age >= 60',
    fn($u) => 'Smith and Sons' === $u['company']['name'] && $u['age'] >= 60],
  ["lastName like 'M%ller' AND score > 50",
    fn($u) => preg_match('/^m.*ller$/i', $u['lastName']) && isset($u['score']) && $u['score'] > 50],
  ['company.address.city in (Berlin, Hamburg, Wien) AND deletedAt is not null',
    fn($u) => in_array($u['company']['address']['city'], ['Berlin', 'Hamburg', 'Wien'], true) && null !== $u['deletedAt']],
  ['score is null', fn($u) => !isset($u['score'])],
];
foreach ($cases as [$where, $ref]) {
  $res = timed("where: {$where}", fn() => $users->select()->where($where)->execute());
  check(count($res).' matches = reference', array_column($res, 'id') === array_column(array_filter($all, $ref), 'id'));
}

$top = timed('orderBy score DESC, age ASC limit 10', fn() => $users->select()
  ->where('status = active')->orderBy('score', 'DESC')->orderBy('age')->limit(10)->execute());
$scores = array_column($top, 'score');
$sorted = $scores;
rsort($sorted);
check('sorted descending', 10 === count($top) && $scores === $sorted);

$first = timed('early limit (limit 5 without orderBy)', fn() => $users->select()->where('age > 20')->limit(5)->execute());
check('5 results', 5 === count($first));

timed('findByKey (O(1))', fn() => $users->findByKey('12345'));

$joined = timed('join users × orders (Hash-Index)', fn() => (new QueryBuilder())
  ->select('user.id, order.amount')
  ->from($all, 'user')
  ->join($orders, 'order', 'user.id = order.user_id')
  ->where('order.status = paid')
  ->execute());
$paid = count(array_filter($orders, fn($o) => 'paid' === $o['status']));
check(count($joined)." join rows = paid orders ({$paid})", count($joined) === $paid);

// ---------------------------------------------------------------------------
echo "\nWrite\n";
$copy = __DIR__.'/data/users_write.json';
copy($usersFile, $copy);
$c = timed('load copy', fn() => new Collection($copy, 'id'));
$before = count($c->getData());
$nextId = max(array_column($c->getData(), 'id')) + 1;

timed('1000× add', function() use ($c, $nextId) {
  for ($i = 0; $i < 1000; $i++) {
    $c->add(['id' => $nextId + $i, 'firstName' => 'New', 'age' => 33, 'status' => 'active']);
  }
});
timed('all inactive → archived (update)', function() use ($c) {
  foreach ($c->select()->where('status = inactive')->execute() as $u) {
    $c->update($u, ['status' => 'archived'] + $u);
  }
});
timed('delete via callable (age > 75)', fn() => $c->delete(fn($u) => $u['age'] > 75));
timed('save (atomic)', fn() => $c->save());

$reloaded = timed('reload', fn() => new Collection($copy, 'id'));
$expected = $before + 1000 - count(array_filter($all, fn($u) => $u['age'] > 75));
check("count after writing ({$expected})", count($reloaded->getData()) === $expected);
check('no inactive left', 0 === count($reloaded->select()->where('status = inactive')->execute()));
check('new records found by key', 'New' === ($reloaded->findByKey((string)$nextId)['firstName'] ?? null));
check('no .tmp files left', [] === glob(__DIR__.'/data/*.tmp'));
unlink($copy);

printf("\nPeak Memory: %.0f MB\n", memory_get_peak_usage(true) / 1048576);
echo $failed ? "\n{$failed} test(s) failed\n" : "\nAll tests ok\n";
exit($failed ? 1 : 0);
