<?php
declare(strict_types=1);

/**
 * QueryBuilder
 *
 * SQL-like queries on an array of records.
 *
 * Performance optimisations over v1:
 *  - Parsed-condition cache: parseCondition() runs only once per unique string.
 *  - LIKE patterns are compiled to a regex once.
 *  - IN sets are stored as a hash map via array_flip() (O(1) instead of O(n)).
 *  - resolveField() paths are split once and cached.
 *  - Join index: equality joins build a hash index on the join side up front.
 *  - Early limit: without orderBy/groupBy, filtering stops once offset + limit rows have matched.
 */
class QueryBuilder {

  protected array $baseData = [];
  protected ?string $baseAlias = null;
  /** @var array<int, array{alias:string, data:array, onCondition:callable, indexKey:string|null}> */
  protected array $joins = [];
  /** @var array<int, string> */
  protected array $selectedFields = [];
  /** @var array<int, callable> */
  protected array $conditions = [];
  /** @var array<int, array{field:string, direction:string}> */
  protected array $orderBy = [];
  /** @var array<int, string> */
  protected array $groupBy = [];
  protected ?int $limitValue = null;
  protected int $offsetValue = 0;

  /** @var array<string, callable> Parsed-condition cache (keyed by condition string + alias) */
  private static array $conditionCache = [];

  /** @var array<string, array<int, string>> Resolved field-path cache */
  private static array $fieldPathCache = [];

  // ---------------------------------------------------------------------------
  // Builder API
  // ---------------------------------------------------------------------------

  public function select($fields): self {
    if (is_string($fields)) {
      $this->selectedFields = array_map('trim', explode(',', $fields));
    } elseif (is_array($fields)) {
      $this->selectedFields = $fields;
    }
    return $this;
  }

  public function from($source, ?string $alias = null): self {
    if ($source instanceof Collection) {
      $this->baseData = $source->getData();
    } else {
      $this->baseData = self::loadSource($source);
    }
    $this->baseAlias = $alias;
    return $this;
  }

  public function join($source, string $alias, $onCondition): self {
    if ('' === $alias) {
      throw new Exception('Alias is required for join.');
    }
    if ($source instanceof Collection) {
      $data = $source->getData();
    } else {
      $data = self::loadSource($source);
    }

    $indexKey = null;
    $baseField = null;
    $pairs = [];

    if (is_string($onCondition)) {
      // Detect a simple equality join so we can build a hash-index
      if (preg_match('/^([\w\.]+)\s*=\s*([\w\.]+)$/', $onCondition, $m)) {
        $pairs = [$m[1] => $m[2]];
      }
      $onCondition = self::parseJoinCondition($onCondition);
    } elseif (is_array($onCondition)) {
      $pairs = $onCondition;
      $onCondition = self::parseJoinArrayCondition($onCondition);
    }

    // Find the first pair with one side on the new alias: that side gets indexed, the other is looked up
    foreach ($pairs as $l => $r) {
      if (0 === strpos($r, $alias.'.')) {
        [$indexKey, $baseField] = [substr($r, strlen($alias) + 1), $l];
        break;
      }
      if (0 === strpos($l, $alias.'.')) {
        [$indexKey, $baseField] = [substr($l, strlen($alias) + 1), $r];
        break;
      }
    }

    $index = null;
    if (null !== $indexKey) {
      $index = [];
      foreach ($data as $row) {
        $val = self::resolveField($row, $indexKey);
        if (is_scalar($val)) {
          $index[(string)$val][] = $row;
        }
      }
    }

    $this->joins[] = [
      'alias'       => $alias,
      'data'        => $data,
      'onCondition' => $onCondition,
      'index'       => $index,
      'baseField'   => $baseField,
    ];
    return $this;
  }

  public function where($condition): self {
    $this->conditions[] = $this->toCallable($condition);
    return $this;
  }

  public function andWhere($condition): self {
    return $this->where($condition);
  }

  public function orWhere($condition): self {
    if (empty($this->conditions)) {
      return $this->where($condition);
    }
    $condition = $this->toCallable($condition);
    $last = array_pop($this->conditions);
    $newCondition = static function(array $record) use ($last, $condition): bool {
      return $last($record) || $condition($record);
    };
    $this->conditions[] = $newCondition;
    return $this;
  }

  private function toCallable($condition): callable {
    if (is_string($condition)) {
      return self::parseCondition($condition, empty($this->joins) ? $this->baseAlias : null);
    }
    if ($condition instanceof Condition) {
      return static fn(array $record): bool => $condition->evaluate($record);
    }
    return $condition;
  }

  /** Group rows by one or more fields; combine with SUM/COUNT/AVG/MIN/MAX in select(). */
  public function groupBy(string ...$fields): self {
    foreach ($fields as $field) {
      array_push($this->groupBy, ...array_map('trim', explode(',', $field)));
    }
    return $this;
  }

  public function orderBy(string $field, string $direction = 'ASC'): self {
    $this->orderBy[] = ['field' => $field, 'direction' => strtoupper($direction)];
    return $this;
  }

  public function limit(int $limit): self {
    $this->limitValue = $limit;
    return $this;
  }

  public function offset(int $offset): self {
    $this->offsetValue = $offset;
    return $this;
  }

  // ---------------------------------------------------------------------------
  // Execution
  // ---------------------------------------------------------------------------

  public function execute(): array {
    // 1. Build cross-product / joins
    $finalData = $this->buildJoinedData();

    // 2. Filter
    $results = [];
    $conditions = $this->conditions; // local ref for perf
    $aggregates = $this->parseAggregates();
    $grouping = !empty($aggregates) || !empty($this->groupBy);
    // Without sorting/grouping we can stop as soon as offset + limit rows matched
    $stopAt = (empty($this->orderBy) && !$grouping && null !== $this->limitValue) ? $this->offsetValue + $this->limitValue : null;
    foreach ($finalData as $record) {
      if (null !== $stopAt && count($results) >= $stopAt) {
        break;
      }
      $match = true;
      foreach ($conditions as $cond) {
        if (!$cond($record)) {
          $match = false;
          break;
        }
      }
      if ($match) {
        $results[] = $record;
      }
    }

    // 3. Group + aggregate (rows are already projected afterwards)
    if ($grouping) {
      $results = $this->aggregate($results, $aggregates);
    }

    // 4. Sort (must happen before limit); grouped rows have flat keys like "total" or "user.name"
    if (!empty($this->orderBy)) {
      $orderBy = $this->orderBy;
      $get = static fn(array $row, string $field) => array_key_exists($field, $row) ? $row[$field] : self::resolveField($row, $field);
      usort($results, static function(array $a, array $b) use ($orderBy, $get): int {
        foreach ($orderBy as $order) {
          $valA = $get($a, $order['field']);
          $valB = $get($b, $order['field']);
          if ($valA == $valB) {
            continue;
          }
          return $order['direction'] === 'ASC'
            ? (($valA < $valB) ? -1 : 1)
            : (($valA > $valB) ? -1 : 1);
        }
        return 0;
      });
    }

    // 5. Offset / limit
    if ($this->offsetValue > 0) {
      $results = array_slice($results, $this->offsetValue);
    }
    if ($this->limitValue !== null) {
      $results = array_slice($results, 0, $this->limitValue);
    }

    // 6. Field projection
    if ($grouping || empty($this->selectedFields)) {
      return $results;
    }
    return array_map(fn(array $record) => $this->project($record, $this->selectedFields), $results);
  }

  private function project(array $record, array $fields): array {
    $row = [];
    foreach ($fields as $field) {
      if (false === strpos($field, '.') || '.*' === substr($field, -2)) {
        $alias = (false === strpos($field, '.')) ? $field : substr($field, 0, -2);
        $row[$alias] = $record[$alias] ?? null;
      } else {
        $row[$field] = self::resolveField($record, $field);
      }
    }
    return $row;
  }

  /**
   * Aggregate expressions from select(), e.g. "SUM(order.amount) AS total".
   * @return array<string, array{fn:string, field:string}> keyed by output name
   */
  private function parseAggregates(): array {
    $aggregates = [];
    foreach ($this->selectedFields as $field) {
      if (preg_match('/^(sum|count|avg|min|max)\(\s*([\w\.\*]+)\s*\)(?:\s+as\s+(\w+))?$/i', $field, $m)) {
        $aggregates[$m[3] ?? $field] = ['fn' => strtolower($m[1]), 'field' => $m[2]];
      }
    }
    return $aggregates;
  }

  /** One row per group (or one row total without groupBy): plain fields from the group's first record + aggregates. */
  private function aggregate(array $records, array $aggregates): array {
    $plainFields = array_values(array_filter($this->selectedFields, fn($f) => !preg_match('/^(sum|count|avg|min|max)\(/i', $f)));
    if (empty($this->selectedFields)) {
      $plainFields = $this->groupBy;
    }

    $groups = [];
    foreach ($records as $record) {
      $key = json_encode(array_map(static fn($f) => self::resolveField($record, $f), $this->groupBy));
      $groups[$key][] = $record;
    }
    if (empty($groups) && empty($this->groupBy)) {
      $groups[''] = []; // SELECT SUM(...) without matches still returns one row
    }

    $rows = [];
    foreach ($groups as $group) {
      $row = isset($group[0]) ? $this->project($group[0], $plainFields) : array_fill_keys($plainFields, null);
      foreach ($aggregates as $name => $agg) {
        $values = '*' === $agg['field']
          ? $group
          : array_filter(array_map(static fn($r) => self::resolveField($r, $agg['field']), $group), static fn($v) => null !== $v);
        switch ($agg['fn']) {
          case 'count': $row[$name] = count($values); break;
          case 'sum':   $row[$name] = array_sum($values); break;
          case 'avg':   $row[$name] = $values ? array_sum($values) / count($values) : null; break;
          case 'min':   $row[$name] = $values ? min($values) : null; break;
          case 'max':   $row[$name] = $values ? max($values) : null; break;
        }
      }
      $rows[] = $row;
    }
    return $rows;
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  private function buildJoinedData(): array {
    if (empty($this->joins)) {
      if (null !== $this->baseAlias) {
        $result = [];
        foreach ($this->baseData as $row) {
          $result[] = [$this->baseAlias => $row];
        }
        return $result;
      }
      return $this->baseData;
    }

    if (null === $this->baseAlias) {
      throw new Exception('Base alias required when using joins.');
    }

    $finalData = [];
    foreach ($this->baseData as $row) {
      $finalData[] = [$this->baseAlias => $row];
    }

    foreach ($this->joins as $join) {
      $alias = $join['alias'];
      $onCondition = $join['onCondition'];
      $index = $join['index'];
      $newData = [];

      foreach ($finalData as $record) {
        if (null !== $index) {
          // Hash-index join: only candidates with the same key value, full condition still checked
          $key = self::resolveField($record, $join['baseField']);
          if (!is_scalar($key)) { continue; }
          foreach ($index[(string)$key] ?? [] as $joinRow) {
            $candidate = $record;
            $candidate[$alias] = $joinRow;
            if ($onCondition($candidate)) {
              $newData[] = $candidate;
            }
          }
        } else {
          // Fallback: nested-loop join
          foreach ($join['data'] as $joinRow) {
            $newRecord = $record;
            $newRecord[$alias] = $joinRow;
            if ($onCondition($newRecord)) {
              $newData[] = $newRecord;
            }
          }
        }
      }

      $finalData = $newData;
    }

    return $finalData;
  }

  /**
   * Resolve a dot-notation field path within a record.
   * Uses a static path cache to avoid repeated explode() calls.
   */
  private static function resolveField(array $record, string $fieldPath) {
    if (!isset(self::$fieldPathCache[$fieldPath])) {
      self::$fieldPathCache[$fieldPath] = explode('.', $fieldPath);
    }
    $parts = self::$fieldPathCache[$fieldPath];
    $first = $parts[0];
    if (!isset($record[$first])) {
      return null;
    }
    $value = $record[$first];
    $n = count($parts);
    for ($i = 1; $i < $n; $i++) {
      $part = $parts[$i];
      if (is_array($value) && array_key_exists($part, $value)) {
        $value = $value[$part];
      } else {
        return null;
      }
    }
    return $value;
  }

  // ---------------------------------------------------------------------------
  // Condition parsing (with static cache)
  // ---------------------------------------------------------------------------

  public static function parseCondition(string $conditionString, ?string $alias = null): callable {
    $cacheKey = $conditionString.'|'.$alias;
    if (isset(self::$conditionCache[$cacheKey])) {
      return self::$conditionCache[$cacheKey];
    }

    $callable = self::buildCondition(trim($conditionString), $alias);
    self::$conditionCache[$cacheKey] = $callable;
    return $callable;
  }

  private static function buildCondition(string $conditionString, ?string $alias): callable {
    // Strip redundant outer parentheses: "(a OR b)" -> "a OR b"
    while (self::isWrapped($conditionString)) {
      $conditionString = trim(substr($conditionString, 1, -1));
    }

    // OR splits (lowest precedence), then AND – only outside quotes/parentheses
    $parts = self::splitTopLevel($conditionString, 'OR');
    if (count($parts) > 1) {
      $conditions = array_map(static fn($p) => self::parseCondition($p, $alias), $parts);
      return static function(array $record) use ($conditions): bool {
        foreach ($conditions as $cond) {
          if ($cond($record)) { return true; }
        }
        return false;
      };
    }

    $parts = self::splitTopLevel($conditionString, 'AND');
    if (count($parts) > 1) {
      $conditions = array_map(static fn($p) => self::parseCondition($p, $alias), $parts);
      return static function(array $record) use ($conditions): bool {
        foreach ($conditions as $cond) {
          if (!$cond($record)) { return false; }
        }
        return true;
      };
    }

    // IS [NOT] NULL
    if (preg_match('/^([\w\.]+)\s+is\s+(not\s+)?null$/i', $conditionString, $matches)) {
      $field = $matches[1];
      $wantNull = empty($matches[2]);
      return static function(array $record) use ($alias, $field, $wantNull): bool {
        if (!self::matchesAlias($field, $alias)) { return false; }
        return (self::resolveField($record, $field) === null) === $wantNull;
      };
    }

    // [NOT] IN – precompute flip set for O(1) lookups
    if (preg_match('/^([\w\.]+)\s+(not\s+)?in\s*\((.+)\)$/i', $conditionString, $matches)) {
      $field = $matches[1];
      $negate = !empty($matches[2]);
      $valueSet = array_flip(array_map(static fn($v) => trim($v, "\"' "), explode(',', $matches[3])));
      return static function(array $record) use ($alias, $field, $valueSet, $negate): bool {
        if (!self::matchesAlias($field, $alias)) { return false; }
        $fieldValue = self::resolveField($record, $field);
        $found = is_scalar($fieldValue) && isset($valueSet[(string)$fieldValue]);
        return $found !== $negate;
      };
    }

    // [NOT] LIKE – pattern compiled to a regex once
    if (preg_match('/^([\w\.]+)\s+(not\s+)?like\s+(.+)$/i', $conditionString, $matches)) {
      $field = $matches[1];
      $negate = !empty($matches[2]);
      $likeRegex = self::compileLikePattern(trim($matches[3], "\"' "));
      return static function(array $record) use ($alias, $field, $likeRegex, $negate): bool {
        if (!self::matchesAlias($field, $alias)) { return false; }
        $fieldValue = self::resolveField($record, $field);
        if (!is_scalar($fieldValue)) { return false; }
        return (bool)preg_match($likeRegex, (string)$fieldValue) !== $negate;
      };
    }

    // Comparison
    if (preg_match('/^([\w\.]+)\s*(=|<>|!=|>=|<=|>|<)\s*(.+)$/', $conditionString, $matches)) {
      $field    = $matches[1];
      $operator = $matches[2];
      $value    = self::parseLiteral($matches[3]);
      // true/false/null compare strictly, otherwise a missing field would equal false
      $strict   = is_bool($value) || null === $value;

      return static function(array $record) use ($alias, $field, $operator, $value, $strict): bool {
        if (!self::matchesAlias($field, $alias)) { return false; }
        $fieldValue = self::resolveField($record, $field);
        switch ($operator) {
          case '=':   return $strict ? $fieldValue === $value : $fieldValue == $value;
          case '<>':
          case '!=':  return $strict ? $fieldValue !== $value : $fieldValue != $value;
        }
        // Missing fields never satisfy ordering comparisons (null < 30 would be true in PHP)
        if (null === $fieldValue) { return false; }
        switch ($operator) {
          case '>':   return $fieldValue > $value;
          case '<':   return $fieldValue < $value;
          case '>=':  return $fieldValue >= $value;
          case '<=':  return $fieldValue <= $value;
        }
        return false;
      };
    }

    throw new Exception("Cannot parse condition: {$conditionString}");
  }

  private static function matchesAlias(string $field, ?string $alias): bool {
    return null === $alias || 0 === strpos($field.'.', $alias.'.');
  }

  /** Unquoted true/false/null/numbers become typed values; quoted values stay strings. */
  private static function parseLiteral(string $raw) {
    $raw = trim($raw);
    if (preg_match('/^(["\'])(.*)\1$/s', $raw, $m)) {
      return $m[2];
    }
    $lower = strtolower($raw);
    if ('true' === $lower) { return true; }
    if ('false' === $lower) { return false; }
    if ('null' === $lower) { return null; }
    return is_numeric($raw) ? $raw + 0 : $raw;
  }

  /**
   * Split on a keyword (AND/OR) surrounded by whitespace, ignoring occurrences
   * inside quotes or parentheses.
   */
  private static function splitTopLevel(string $s, string $keyword): array {
    $parts = [];
    $depth = 0;
    $quote = null;
    $start = 0;
    $len = strlen($s);
    $kwLen = strlen($keyword);
    for ($i = 0; $i < $len; $i++) {
      $c = $s[$i];
      if (null !== $quote) {
        if ($c === $quote) { $quote = null; }
      } elseif ('"' === $c || "'" === $c) {
        $quote = $c;
      } elseif ('(' === $c) {
        $depth++;
      } elseif (')' === $c) {
        $depth--;
      } elseif (0 === $depth && ctype_space($c) && $i + $kwLen + 1 < $len
        && 0 === substr_compare($s, $keyword, $i + 1, $kwLen, true)
        && ctype_space($s[$i + $kwLen + 1])) {
        $parts[] = trim(substr($s, $start, $i - $start));
        $i += $kwLen + 1;
        $start = $i;
      }
    }
    $parts[] = trim(substr($s, $start));
    return array_values(array_filter($parts, static fn($p) => '' !== $p));
  }

  /** True if the whole string is enclosed in one matching pair of parentheses. */
  private static function isWrapped(string $s): bool {
    $len = strlen($s);
    if ($len < 2 || '(' !== $s[0] || ')' !== $s[$len - 1]) {
      return false;
    }
    $depth = 0;
    $quote = null;
    for ($i = 0; $i < $len - 1; $i++) {
      $c = $s[$i];
      if (null !== $quote) {
        if ($c === $quote) { $quote = null; }
      } elseif ('"' === $c || "'" === $c) {
        $quote = $c;
      } elseif ('(' === $c) {
        $depth++;
      } elseif (')' === $c && 0 === --$depth) {
        return false;
      }
    }
    return true;
  }

  /** Compile a SQL LIKE pattern (% = any, _ = one char) to an anchored, case-insensitive regex. */
  private static function compileLikePattern(string $likeValue): string {
    return '/^'.strtr(preg_quote($likeValue, '/'), ['%' => '.*', '_' => '.']).'$/is';
  }

  public static function parseJoinCondition(string $conditionString): callable {
    if (preg_match('/^([\w\.]+)\s*=\s*([\w\.]+)$/', $conditionString, $matches)) {
      $left  = $matches[1];
      $right = $matches[2];
      return static function(array $record) use ($left, $right): bool {
        return self::resolveField($record, $left) == self::resolveField($record, $right);
      };
    }
    return static function(array $record): bool { return false; };
  }

  public static function parseJoinArrayCondition(array $conditionArray): callable {
    return static function(array $record) use ($conditionArray): bool {
      foreach ($conditionArray as $fieldLeft => $fieldRight) {
        if (self::resolveField($record, $fieldLeft) != self::resolveField($record, $fieldRight)) {
          return false;
        }
      }
      return true;
    };
  }

  public static function loadSource($source): array {
    if (is_string($source) && file_exists($source)) {
      $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
      if ('json' === $ext) {
        $data = json_decode(file_get_contents($source), true);
        if (null === $data) {
          throw new Exception("Invalid JSON in file: {$source}");
        }
        return $data;
      }
      if ('xml' === $ext) {
        $xml = simplexml_load_file($source, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (false === $xml) {
          throw new Exception("Invalid XML in file: {$source}");
        }
        return json_decode(json_encode($xml), true);
      }
      throw new Exception("Unsupported file type: {$ext}");
    }
    if (is_array($source)) {
      return $source;
    }
    throw new Exception('Invalid source. Expected an array or a valid file path.');
  }

  /** Clear the static caches (useful in tests or long-running processes). */
  public static function clearCache(): void {
    self::$conditionCache = [];
    self::$fieldPathCache = [];
  }
}