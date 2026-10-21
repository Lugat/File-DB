<?php
declare(strict_types=1);

/**
 * Condition
 *
 * Builds complex filter conditions.
 * Uses QueryBuilder::parseCondition() internally, so there is no duplicated parsing logic.
 */
class Condition {

  protected ?string $alias;
  /** @var array<int, callable> */
  protected array $conditions = [];
  /** @var array<int, string> */
  protected array $operators = [];

  public function __construct(?string $alias = null) {
    $this->alias = $alias;
  }

  public function where($cond): self {
    $this->conditions = [];
    $this->operators  = [];
    foreach ($this->normalizeCond($cond) as $callable) {
      $this->conditions[] = $callable;
    }
    return $this;
  }

  public function andWhere($cond): self {
    foreach ($this->normalizeCond($cond) as $callable) {
      $this->operators[]  = 'AND';
      $this->conditions[] = $callable;
    }
    return $this;
  }

  public function orWhere($cond): self {
    if (is_array($cond)) {
      $andGroup = new self($this->alias);
      $andGroup->where($cond);
      $this->operators[]  = 'OR';
      $this->conditions[] = static function(array $record) use ($andGroup): bool {
        return $andGroup->evaluate($record);
      };
    } else {
      $this->operators[]  = 'OR';
      $this->conditions[] = $this->toCallable($cond);
    }
    return $this;
  }

  public function evaluate(array $record): bool {
    if (empty($this->conditions)) {
      return true;
    }
    $result = ($this->conditions[0])($record);
    $n = count($this->conditions);
    for ($i = 1; $i < $n; $i++) {
      $op = $this->operators[$i - 1] ?? 'AND';
      $condResult = ($this->conditions[$i])($record);
      $result = 'AND' === $op ? $result && $condResult : $result || $condResult;
    }
    return $result;
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  /** Normalise a mixed condition into an array of callables. */
  private function normalizeCond($cond): array {
    if (is_array($cond)) {
      $callables = [];
      foreach ($cond as $key => $value) {
        $conditionString = is_array($value)
          ? $key.' in ('.implode(', ', $value).')'
          : $key.' = "'.addslashes((string)$value).'"';
        $callables[] = QueryBuilder::parseCondition($conditionString, $this->alias);
      }
      return $callables;
    }
    return [$this->toCallable($cond)];
  }

  private function toCallable($cond): callable {
    if (is_string($cond)) {
      return QueryBuilder::parseCondition($cond, $this->alias);
    }
    return $cond;
  }
}