# file-db

A small PHP query builder for arrays and JSON/XML files: SQL-like `where`, `join`, `groupBy`, `orderBy`, `limit` without a database.

**[Live demo →](https://git.jinx-digital.com/file-db)**

## Usage

```php
require_once 'src/Collection.php';
require_once 'src/Condition.php';
require_once 'src/QueryBuilder.php';

$rows = (new QueryBuilder())
  ->select('user.name, SUM(order.amount) AS total, COUNT(*) AS orders')
  ->from('users.json', 'user')
  ->join('orders.json', 'order', 'user.id = order.user_id')
  ->where('user.age >= 18 AND user.status in (active, pending)')
  ->groupBy('user.id')
  ->orderBy('total', 'DESC')
  ->limit(10)
  ->execute();
```

Sources can be arrays or paths to `.json` / `.xml` files.

### Conditions

- Comparison: `=`, `!=`, `<>`, `>`, `<`, `>=`, `<=`
- `LIKE` / `NOT LIKE` (`%` wildcard), `IN (...)` / `NOT IN (...)`, `IS NULL` / `IS NOT NULL`
- `AND`, `OR`, parentheses, dot notation for nested fields (`company.address.city`)
- Array form: `['status' => 'active']`, or build with `Condition`:

```php
$condition = (new Condition())->where('age = 30')->andWhere(['status' => 'active']);
```

### Writing with Collection

```php
$collection = new Collection('people.json', 'id'); // primary key is optional
$collection->add(['id' => 4, 'name' => 'Eve']);
$collection->update($old, $new);
$collection->delete(fn($row) => $row['age'] > 40);
$collection->save();

$collection->select()->where('name = Eve')->execute();
```

## Demo

Open `index.php` in the browser (e.g. via MAMP) to see the example queries and their results.

## Tests

```sh
php tests/generate.php   # once, creates tests/data/*.json
php tests/run.php
```
