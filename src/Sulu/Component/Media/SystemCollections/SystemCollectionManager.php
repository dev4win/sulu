<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Media\SystemCollections;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\MediaBundle\Api\Collection;
use Sulu\Bundle\MediaBundle\Collection\Manager\CollectionManagerInterface;
use Sulu\Component\Cache\CacheInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Manages system-collections.
 */
class SystemCollectionManager implements SystemCollectionManagerInterface
{
    /**
     * @var array
     */
    private $systemCollections;

    /**
     * @param string $locale
     */
    public function __construct(
        private array $config,
        private CollectionManagerInterface $collectionManager,
        private EntityManagerInterface $entityManager,
        private ?TokenStorageInterface $tokenProvider,
        private CacheInterface $cache,
        private $locale,
    ) {
    }

    public function warmUp()
    {
        $this->cache->invalidate();
        $this->getSystemCollections();
    }

    public function getSystemCollection($key)
    {
        $systemCollections = $this->getSystemCollections();

        if (!\array_key_exists($key, $systemCollections)) {
            throw new UnrecognizedSystemCollection($key, \array_keys($systemCollections));
        }

        return $systemCollections[$key];
    }

    public function isSystemCollection($id)
    {
        return \in_array($id, $this->getSystemCollections());
    }

    /**
     * Returns system collections.
     *
     * @return array
     */
    private function getSystemCollections()
    {
        if (!$this->systemCollections) {
            if (!$this->cache->isFresh()) {
                $systemCollections = $this->buildSystemCollections(
                    $this->locale,
                    $this->getUserId()
                );

                $this->cache->write($systemCollections);
            }

            $this->systemCollections = $this->cache->read();
        }

        return $this->systemCollections;
    }

    /**
     * Returns current user.
     *
     * @return int
     */
    private function getUserId()
    {
        if (!$this->tokenProvider || null === ($token = $this->tokenProvider->getToken())) {
            return;
        }

        if (!$token->getUser() instanceof UserInterface) {
            return;
        }

        return $token->getUser()->getId();
    }

    /**
     * Go thru configuration and build all system collections.
     *
     * @param string $locale
     * @param int $userId
     *
     * @return array
     */
    private function buildSystemCollections($locale, $userId)
    {
        $root = $this->getOrCreateRoot(SystemCollectionManagerInterface::COLLECTION_KEY, 'System', $locale, $userId);
        $collections = ['root' => $root->getId()];
        $collections = \array_merge($collections, $this->iterateOverCollections($this->config, $userId, $root->getId()));

        $this->entityManager->flush();

        return $collections;
    }

    /**
     * Iterates over an array of children collections, creates them.
     * This function is recursive!
     *
     * @param array $children
     * @param string $userId
     * @param int|null $parent
     * @param string $namespace
     *
     * @return array
     */
    private function iterateOverCollections($children, $userId, $parent = null, $namespace = '')
    {
        $format = ('' !== $namespace ? '%s.%s' : '%s%s');
        $collections = [];
        foreach ($children as $collectionKey => $collectionItem) {
            $key = \sprintf($format, $namespace, $collectionKey);
            $collections[$key] = $this->getOrCreateCollection(
                $key,
                $collectionItem['meta_title'],
                $userId,
                $parent
            )->getId();

            if (\array_key_exists('collections', $collectionItem)) {
                $childCollections = $this->iterateOverCollections(
                    $collectionItem['collections'],
                    $userId,
                    $collections[$key],
                    $key
                );
                $collections = \array_merge($collections, $childCollections);
            }
        }

        return $collections;
    }

    /**
     * Finds or create a new system-collection namespace.
     *
     * @param string $namespace
     * @param string $title
     * @param string $locale
     * @param int $userId
     * @param int|null $parent id of parent collection or null for root
     *
     * @return Collection
     */
    private function getOrCreateRoot($namespace, $title, $locale, $userId, $parent = null)
    {
        if (null !== ($collection = $this->collectionManager->getByKey($namespace, $locale))) {
            $collection->setTitle($title);

            return $collection;
        }

        return $this->createCollection($title, $namespace, $locale, $userId, $parent);
    }

    /**
     * Finds or create a new system-collection.
     *
     * @param string $key
     * @param array $localizedTitles
     * @param int $userId
     * @param int|null $parent id of parent collection or null for root
     *
     * @return Collection
     */
    private function getOrCreateCollection($key, $localizedTitles, $userId, $parent)
    {
        $locales = \array_keys($localizedTitles);
        $firstLocale = \array_shift($locales);

        $collection = $this->collectionManager->getByKey($key, $firstLocale);
        if (null === $collection) {
            $collection = $this->createCollection($localizedTitles[$firstLocale], $key, $firstLocale, $userId, $parent);
        } else {
            $collection->setTitle($localizedTitles[$firstLocale]);
        }

        foreach ($locales as $locale) {
            $this->createCollection($localizedTitles[$locale], $key, $locale, $userId, $parent, $collection->getId());
        }

        return $collection;
    }

    /**
     * Creates a new collection.
     *
     * @param string $title
     * @param string $key
     * @param string $locale
     * @param int $userId
     * @param int|null $parent id of parent collection or null for root
     * @param int|null $id if not null a colleciton will be updated
     *
     * @return Collection
     */
    private function createCollection($title, $key, $locale, $userId, $parent = null, $id = null)
    {
        $data = [
            'title' => $title,
            'key' => $key,
            'type' => ['id' => 2],
            'locale' => $locale,
        ];

        if (null !== $parent) {
            $data['parent'] = $parent;
        }

        if (null !== $id) {
            $data['id'] = $id;
        }

        return $this->collectionManager->save($data, $userId);
    }
}
