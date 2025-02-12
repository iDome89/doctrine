<?php

declare(strict_types=1);

namespace Baraja\Doctrine\DBAL\Utils;


use Doctrine\DBAL\Driver\Statement;
use LogicException;

final class DataUtils
{

	/**
	 * @param Statement $statement
	 * @param string|null $key
	 * @param string|null $value
	 * @return mixed[]
	 */
	public static function toPairs(Statement $statement, ?string $key = null, ?string $value = null): array
	{
		$rows = $statement->fetchAll();

		if (!$rows) {
			return [];
		}

		$keys = array_keys((array) reset($rows));

		if (!count($keys)) {
			throw new LogicException('Result set does not contain any column.');
		}

		if ($key === null && $value === null) {
			if (count($keys) === 1) {
				[$value] = $keys;
			} else {
				[$key, $value] = $keys;
			}
		}

		$return = [];
		if ($key === null) {
			foreach ($rows as $row) {
				$return[] = ($value === null ? $row : $row[$value]);
			}
		} else {
			foreach ($rows as $row) {
				$return[(string) $row[$key]] = ($value === null ? $row : $row[$value]);
			}
		}

		return $return;
	}

}
