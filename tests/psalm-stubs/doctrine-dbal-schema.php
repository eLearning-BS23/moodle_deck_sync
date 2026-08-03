<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

final class Table {
	public function hasColumn(string $name): bool {
		return false;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function addColumn(string $name, string $type, array $options = []): void {
	}

	/**
	 * @param list<string> $columnNames
	 */
	public function setPrimaryKey(array $columnNames): void {
	}

	/**
	 * @param list<string> $columnNames
	 */
	public function addUniqueIndex(array $columnNames, ?string $indexName = null): void {
	}

	/**
	 * @param list<string> $columnNames
	 */
	public function addIndex(array $columnNames, ?string $indexName = null): void {
	}
}
