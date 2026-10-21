<?php
declare(strict_types=1);

/**
 * Collection
 *
 * Loads data from a file (JSON or XML) and keeps it internally as an array of records.
 * Records are keyed by an optional primary key, otherwise numbered sequentially.
 * Without a primary key, update()/delete() find a record by exact comparison (O(n)).
 */
class Collection {

  protected string $filePath;
  /** @var array<string, array> */
  protected array $data = [];
  protected string $format;
  protected ?string $primaryKey;

  public function __construct(string $filePath, ?string $primaryKey = null) {
    $this->filePath   = $filePath;
    $this->primaryKey = $primaryKey;
    $this->detectFormat();
    $this->load();
  }

  protected function detectFormat(): void {
    $ext = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
    if ('json' === $ext) {
      $this->format = 'json';
    } elseif ('xml' === $ext) {
      $this->format = 'xml';
    } else {
      throw new Exception("Unsupported file format: {$ext}");
    }
  }

  protected function load(): void {
    if (!file_exists($this->filePath)) {
      $this->data = [];
      return;
    }
    if ('json' === $this->format) {
      $decoded = json_decode(file_get_contents($this->filePath), true);
      if (null === $decoded) {
        throw new Exception("Invalid JSON in file: {$this->filePath}");
      }
    } else {
      $xml = simplexml_load_file($this->filePath, 'SimpleXMLElement', LIBXML_NOCDATA);
      if (false === $xml) {
        throw new Exception("Invalid XML in file: {$this->filePath}");
      }
      $decoded = json_decode(json_encode($xml), true);
      if (!is_array($decoded)) {
        $decoded = [];
      }
    }

    $this->data = [];
    foreach ($decoded as $record) {
      $this->add($record);
    }
  }

  /** Key of a record: its primary key value, or (without one) the position of an identical stored record. */
  protected function keyOf(array $record) {
    if (null !== $this->primaryKey && isset($record[$this->primaryKey])) {
      return (string)$record[$this->primaryKey];
    }
    $key = array_search($record, $this->data, true);
    return false === $key ? null : $key;
  }

  /**
   * Adds a new record. If a primary key is configured it is used as the index key.
   */
  public function add(array $record): void {
    if (null !== $this->primaryKey && isset($record[$this->primaryKey])) {
      $this->data[(string)$record[$this->primaryKey]] = $record;
    } else {
      // Identical records without primary key are kept as separate rows (md5 keys used to merge them)
      $this->data[] = $record;
    }
  }

  /**
   * Updates a record. The old record is identified by its hash/primary key.
   */
  public function update(array $oldRecord, array $newRecord): void {
    $oldKey = $this->keyOf($oldRecord);
    if (null !== $oldKey) {
      unset($this->data[$oldKey]);
    }
    $this->add($newRecord);
  }

  /**
   * Deletes records. Accepts a callable, a record array, or a key string.
   */
  public function delete($item): void {
    if (is_callable($item)) {
      foreach ($this->data as $key => $record) {
        if ($item($record)) {
          unset($this->data[$key]);
        }
      }
    } elseif (is_array($item)) {
      $key = $this->keyOf($item);
      if (null !== $key) {
        unset($this->data[$key]);
      }
    } elseif (is_string($item)) {
      unset($this->data[$item]);
    } else {
      throw new Exception('Unsupported type for deletion.');
    }
  }

  /**
   * O(1) lookup by the configured primary key value.
   * Returns null when no primary key is configured or the value is not found.
   */
  public function findByKey(string $value): ?array {
    return $this->data[$value] ?? null;
  }

  /**
   * Returns the current records as a numerically indexed array.
   */
  public function getData(): array {
    return array_values($this->data);
  }

  /**
   * Initiates a QueryBuilder on the current data.
   */
  public function select(): QueryBuilder {
    $qb = new QueryBuilder();
    $qb->from($this->getData());
    return $qb;
  }

  /**
   * Saves current data back to the file.
   */
  public function save(): void {
    $dataToSave = array_values($this->data);
    if ('json' === $this->format) {
      $encoded = json_encode($dataToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      if (false === $encoded) {
        throw new Exception('Error encoding JSON: '.json_last_error_msg());
      }
    } else {
      $encoded = self::arrayToXml($dataToSave)->asXML();
    }
    // Write to a temp file and rename: a crash or concurrent reader never sees a half-written file
    $tmp = $this->filePath.'.'.uniqid('', true).'.tmp';
    if (false === file_put_contents($tmp, $encoded, LOCK_EX) || !rename($tmp, $this->filePath)) {
      @unlink($tmp);
      throw new Exception("Could not write file: {$this->filePath}");
    }
  }

  protected static function arrayToXml(array $data, ?SimpleXMLElement $xml = null): SimpleXMLElement {
    if (null === $xml) {
      $xml = new SimpleXMLElement('<root/>');
    }
    foreach ($data as $key => $value) {
      if (is_numeric($key)) {
        $key = 'item';
      }
      if (is_array($value)) {
        self::arrayToXml($value, $xml->addChild($key));
      } else {
        $xml->addChild($key, htmlspecialchars((string)$value));
      }
    }
    return $xml;
  }
}