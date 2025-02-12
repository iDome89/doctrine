<?php

declare(strict_types=1);

namespace Baraja\Doctrine;


use Doctrine\Common\Persistence\Mapping\MappingException;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Tracy\Debugger;

class DoctrineHelper
{

	/**
	 * @var EntityManager
	 */
	private $entityManager;

	/**
	 * @param EntityManager $entityManager
	 */
	public function __construct(EntityManager $entityManager)
	{
		$this->entityManager = $entityManager;
	}

	/**
	 * Return list of class names which is variant of given entity.
	 * By $exclude you can define list of entities which will be skipped.
	 *
	 * @param string $entity as class name
	 * @param string[]|null $exclude
	 * @return string[]
	 */
	public function getEntityVariants(string $entity, array $exclude = null): array
	{
		$return = [];

		$meta = $this->entityManager->getClassMetadata($entity);

		if (\is_array($meta->discriminatorMap) && \count($meta->discriminatorMap) > 0) {
			foreach ($meta->discriminatorMap as $variant) {
				try {
					$name = Utils::reflectionClassDocComment($variant, 'name');
				} catch (\ReflectionException $e) {
					$name = null;
				}

				if ($name === null) {
					$return[$variant] = preg_replace_callback('/([a-z0-9])([A-Z])/', static function (array $match) {
						return $match[1] . ' ' . strtolower($match[2]);
					}, preg_replace('/^.*?\\\\([^\\\\]+)$/', '$1', $variant));
				} else {
					$return[$variant] = $name;
				}
			}
		}

		foreach ($exclude ?? [] as $excludeItem) {
			unset($return[$excludeItem]);
		}

		return $return;
	}

	/**
	 * Return most embedded entity.
	 * In case of `CustomProduct` extends `Product` extends `BaseProduct`, return `CustomProduct`.
	 *
	 * @param string $entity as class name
	 * @return string
	 */
	public function getBestOfType(string $entity): string
	{
		$variants = $this->getEntityVariants($entity);

		if (\in_array(\count($variants), [0, 1], true)) {
			return $entity;
		}

		$topLength = 0;
		$topType = $entity;

		foreach (array_keys($variants) as $variant) {
			try {
				$length = $this->getParentClassLength(Utils::getReflectionClass($variant));
			} catch (\ReflectionException $e) {
				$length = 0;
			}

			if ($length > $topLength) {
				$topLength = $length;
				$topType = $variant;
			}
		}

		return $topType;
	}

	/**
	 * Return real table name by entity Class name.
	 *
	 * @param string $entity
	 * @return string
	 */
	public function getTableNameByEntity(string $entity): string
	{
		return $this->entityManager->getClassMetadata($entity)->table['name'];
	}

	/**
	 * If extends lot's of entities, return root entity class name.
	 *
	 * @param string $entity
	 * @return string
	 */
	public function getRootEntityName(string $entity): string
	{
		return $this->entityManager->getClassMetadata($entity)->rootEntityName;
	}

	/**
	 * In case of chain inheritance Doctrine store lot's of entities in one table
	 * and distinguishes itself by `discriminator` column.
	 * This method return discriminator column name by given entity Class name.
	 *
	 * @param string $entity
	 * @return string
	 */
	public function getDiscriminatorByEntity(string $entity): string
	{
		$meta = $this->entityManager->getClassMetadata($entity);

		foreach ($meta->discriminatorMap ?? [] as $discriminator => $variant) {
			if ($variant === $entity) {
				return $discriminator;
			}
		}

		$entity = $this->getRootEntityName($entity);
		$meta = $this->entityManager->getClassMetadata($entity);

		foreach ($meta->discriminatorMap ?? [] as $discriminator => $variant) {
			if ($variant === $entity) {
				return $discriminator;
			}
		}

		return '';
	}

	/**
	 * Elevate the type of entity to the best possible and return as new type.
	 * This method can fail if you are tried save missing required columns.
	 * For more information please follow exception messages.
	 *
	 * @param object $from instance of specific entity.
	 * @return object|null
	 * @throws DatabaseException
	 */
	public function remapEntityToBestType($from)
	{
		$fromType = get_class($from);
		$bestType = $this->getBestOfType($fromType);

		if ($fromType === $bestType) {
			return $from;
		}

		return $this->remapEntity($from, $bestType);
	}

	/**
	 * Remap one type of entity to new type in database.
	 * This feature is not supported in Doctrine therefore, the change may not be stable.
	 * When you remap entity type, please select all data again, because Doctrine internal memory can be damaged.
	 * Best practice is refresh page or break CLI process after this change.
	 *
	 * @param object $from instance of specific entity.
	 * @param object|string $to
	 * @return object|null
	 * @throws DatabaseException
	 */
	public function remapEntity($from, $to)
	{
		$toType = is_string($to) ? $to : \get_class($to);
		$fromDiscriminator = $this->getDiscriminatorByEntity(\get_class($from));
		$toDiscriminator = $this->getDiscriminatorByEntity($toType);

		if ($fromDiscriminator === $toDiscriminator) {
			return $from;
		}

		$fromMetaData = $this->entityManager->getClassMetadata(\get_class($from));
		$fromTable = $fromMetaData->getTableName();
		$toTable = $this->entityManager->getClassMetadata($toType)->getTableName();
		$discriminatorColumn = $fromMetaData->discriminatorColumn['fieldName'];

		if ($fromTable !== $toTable) {
			DatabaseException::remapDifferentTypes($fromTable, $toTable);
		}

		try {
			$this->entityManager->clear($this->getRootEntityName(\get_class($from)));
		} catch (MappingException $e) {
			Debugger::log($e);
			DatabaseException::e($e);
		}

		try {
			$this->entityManager->getConnection()->executeUpdate(
				str_replace(
					['{table}', '{discriminatorColumn}', '{discriminator}', '{id}'],
					[$fromTable, $discriminatorColumn, $toDiscriminator, $from->getId()],
					'UPDATE `{table}` '
					. 'SET `{discriminatorColumn}` = \'{discriminator}\' '
					. 'WHERE `id` = \'{id}\''
				)
			);
		} catch (DBALException $e) {
			Debugger::log($e);
			trigger_error($e->getMessage());
		}

		return $this->entityManager->getRepository($toType)->find($from->getId());
	}

	/**
	 * Count position of entity in list and save integer back by setPosition().
	 *
	 * @param object $itemEntity
	 * @param string|null $previousId
	 * @param string|null $parentId
	 * @throws DatabaseException
	 * @throws EntityManagerException
	 */
	public function sortEntities(
		$itemEntity,
		?string $previousId = null,
		?string $parentId = null
	): void
	{
		if (method_exists($itemEntity, 'getId')
			&& method_exists($itemEntity, 'getParent')
			&& method_exists($itemEntity, 'setParent')
			&& method_exists($itemEntity, 'setPosition')
		) {
			$parent = $itemEntity->getParent();

			if ($parent !== null && method_exists($parent, 'getId') && $parent->getId() !== $parentId) {
				try {
					$parent = $this->entityManager->getRepository(\get_class($itemEntity))
						->createQueryBuilder('e')
						->where('e.id = :id')
						->setParameter('id', $parentId)
						->orderBy('e.position', 'ASC')
						->getQuery()
						->getSingleResult();
				} catch (NoResultException|NonUniqueResultException $e) {
					DatabaseException::e($e);
				}

				$itemEntity->setParent($parent);
			}

			if ($parent === null) { // root entity
				$items = $this->entityManager->getRepository(\get_class($itemEntity))
					->createQueryBuilder('e')
					->where('e.parent IS NULL')
					->orderBy('e.position', 'ASC')
					->getQuery()
					->getResult();
			} else {
				$items = $parent->getChildren();
			}

			$position = 0;
			$categoryWasSet = false;

			if ($previousId === null) {
				$itemEntity->setPosition(0);
				$position++;
				foreach ($items ?? [] as $item) {
					if ($item->getId() !== $itemEntity->getId()) {
						$item->setPosition($position);
						$position++;
					}
				}
			} else {
				foreach ($items ?? [] as $item) {
					if ($item->getId() === $previousId) {
						$item->setPosition($position);
						$position++;
						$itemEntity->setPosition($position);
						$position++;
					} elseif ($item->getId() !== $previousId) {
						$item->setPosition($position);
						$position++;
					} elseif ($previousId !== null) {
						$categoryWasSet = true;
					}
				}

				if ($categoryWasSet === false) {
					$itemEntity->setPosition($position);
				}
			}

			$this->entityManager->flush();
		} else {
			DatabaseException::entityMustImplement(\get_class($itemEntity));
		}
	}

	/**
	 * Return relative direction between given entity and root entity by exploring parents.
	 *
	 * In case of `CustomProduct` extends `Product` extends `BaseProduct`, return:
	 * for CustomProduct -> 3
	 * for Product       -> 2
	 * for BaseProduct   -> 1
	 *
	 * @param \ReflectionClass $reflection
	 * @param int $bind
	 * @return int
	 */
	private function getParentClassLength(\ReflectionClass $reflection, int $bind = 1): int
	{
		$parent = $reflection->getParentClass();

		if ($parent === false) {
			return $bind;
		}

		return $this->getParentClassLength($parent, $bind) + $bind;
	}

}